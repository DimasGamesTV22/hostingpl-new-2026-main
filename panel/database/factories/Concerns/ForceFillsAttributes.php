<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Фабрики тестов не обязаны уважать $fillable.
 *
 * В боевом коде $fillable — это защита от mass-assignment из пользовательского
 * ввода: порты, адреса и сроки аренды сервера назначает только панель, поэтому
 * их нет в списке. В тестах же нужно выставить любое поле таблицы напрямую,
 * иначе значения молча теряются (Model по умолчанию не бросает исключение).
 *
 * Трейт применяет атрибуты через forceFill, не расширяя $fillable у модели —
 * production-поверхность не меняется.
 */
trait ForceFillsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Model
    {
        $class = $this->modelName();

        $model = new $class;
        $model->forceFill($attributes);

        if (isset($this->connection)) {
            $model->setConnection($this->connection);
        }

        return $model;
    }
}
