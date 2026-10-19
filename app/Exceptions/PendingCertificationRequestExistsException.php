<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class PendingCertificationRequestExistsException extends Exception
{
    public function __construct(
        string $message = 'Esiste già una richiesta di certificazione in attesa per questo utente e questo layer.',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
