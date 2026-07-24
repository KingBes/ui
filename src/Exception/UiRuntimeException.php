<?php
declare(strict_types=1);

namespace Kingbes\Ui\Exception;

/**
 * 库内部运行期错误（FFI 调用失败、句柄无效、状态不一致等）。
 *
 * 与 UiException（基类）相比，本异常专门描述非用户误用导致的
 * 运行期故障，便于上层做差异化处理。
 */
class UiRuntimeException extends UiException
{
}
