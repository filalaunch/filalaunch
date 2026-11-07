<?php

namespace App\Support\ModuleBuilder;

use RuntimeException;
use Throwable;

class ModuleBuilderException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors, ?Throwable $previous = null)
    {
        parent::__construct(implode(' ', $errors), 0, $previous);
    }

    /**
     * @param  list<string>  $errors
     */
    public static function invalid(array $errors): self
    {
        return new self($errors);
    }

    public static function failed(Throwable $e): self
    {
        return new self(['Generation failed and all changes were rolled back: '.$e->getMessage()], $e);
    }
}
