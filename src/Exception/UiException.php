<?php
declare(strict_types=1);

namespace Kingbes\Ui\Exception;

/**
 * Kingbes\Ui 库所有异常的基类。
 *
 * 继承 \RuntimeException，便于上层用 catch (\Kingbes\Ui\Exception\UiException)
 * 一次性捕获本库抛出的所有运行期错误。
 */
class UiException extends \RuntimeException
{
}
