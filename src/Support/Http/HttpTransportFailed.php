<?php

declare(strict_types=1);

namespace MaiMind\Support\Http;

use RuntimeException;

/** No se llegó a hablar con el servidor: DNS, TLS, timeout, red caída. */
final class HttpTransportFailed extends RuntimeException
{
}
