<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Decisione su una richiesta di certificazione non ammessa (oc:8671).
 * Il messaggio è già tradotto: Nova e il controller di conferma lo mostrano
 * così com'è al gestore.
 */
class CertificationDecisionException extends RuntimeException {}
