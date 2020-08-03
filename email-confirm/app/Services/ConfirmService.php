<?php

namespace App\Services;

use App\Code;
use App\Confirm;
use App\ConfirmType;
use Illuminate\Support\Arr;

class ConfirmService
{
    protected function generateCode()
    {
        return mt_rand(1000, 9999);
    }

    /**
     * @param string $object
     * @param integer $type
     * @return \Illuminate\Database\Eloquent\Model
     */
    protected function getObject($object, $type)
    {
        $confirm = Confirm::query()
            ->where('object', '=',$object)
            ->where('type', '=', $type)
            ->firstOrNew();

        if (!$confirm->id) {
            $confirm->object = $object;
            $confirm->type = $type;
            $confirm->save();
        }

        return $confirm;
    }

    /**
     * @param Confirm $object
     * @return \Illuminate\Database\Eloquent\Model
     * @throws \Exception
     */
    protected function getCode($object)
    {
        /** @var Code $code */
        $code = $object
            ->codes()
            ->where('is_active', '=', true)
            ->whereDate('created_at', '>', (new \DateTime())->sub(new \DateInterval('PT300S')))
            ->firstOrNew([]);

        if (!$code->id) {
            $code->code = $this->generateCode();
            $code->save();
        }

        $object
            ->codes()
            ->where('id', '!=', $code->id)
            ->update(['is_active' => false])
        ;

        return $code;
    }

    public function getVerifyingService($queryData)
    {
        // @todo перенести в фабрики
        if (Arr::exists($queryData, 'email')) {
            /** @var EmailService $service */
            $service = app()->get(EmailService::class);
            $object = $this->getObject(Arr::get($queryData, 'email'), ConfirmType::EMAIL);
            $this->validate($object);
            $code = $this->getCode($object);
            $service->setObject($object);
            $service->setCode($code);
            return $service;
        }

        throw new \Exception('Не найден способ потверждения');
    }

    /**
     * @param Confirm $object
     * @throws \Exception
     */
    protected function validate($object)
    {
        // не больше 5 в течение часа
        $count = $object->codes()
            ->whereDate('created_at', '<=', (new \DateTime())->add(new \DateInterval('PT1H')))
            ->count();
        ;
        if ($count > 5) {
            throw new \Exception('Превышен лимит запросов кода');
        }

        // Код не чаще 1 раза в 5 минут
        $lastCode = $object->codes()->latest()->first();
        if ($lastCode) {
            $timeLater = time() - $lastCode->created_at->timestamp;
            if ($timeLater < Code::LIFETIME) {
                throw new \Exception(sprintf('Код можно будет запросить через %d сек.', (Code::LIFETIME - $timeLater)));
            }
        }
    }

    /**
     * Проверка кода
     *
     * @param $queryData
     */
    public function confirm($queryData)
    {
        $code = Arr::get($queryData, 'code');
        /** @var Code $code */
        $code = Code::query()
            ->where('code', '=', $code)->where('is_active', true)
            ->firstOrFail();

        /** @var Confirm $object */
        $object = $code->object()->first();
        $type = Arr::get(ConfirmType::listTypesAll(), $object->type);

        if ($object->object !== Arr::get($queryData, $type)) {
            $object->try_count++;
            $object->save();

            if ($object->try_count > 3) {
                $code->is_active = false;
                $code->save();
            }

            throw new \Exception('Неверный код');
        }

        $code->is_active = false;
        $code->save();

        $object->try_count = 0;
        $object->send_count = 0;
        $object->save();
    }
}
