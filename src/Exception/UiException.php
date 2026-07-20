<?php
declare(strict_types=1);

namespace Kingbes\Ui\Exception;

/**
 * GUI 库统一异常类型。
 * 所有库内异常继承自它，便于使用者一次性 catch。
 */
class UiException extends \Exception
{
}
