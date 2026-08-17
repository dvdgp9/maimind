-- =====================================================================
-- Registro de intentos de acceso, para frenar la fuerza bruta.
--
-- Se guarda el hash del identificador, nunca el correo ni la IP en claro:
-- esta tabla no debe convertirse en una lista de quién intentó entrar y
-- desde dónde. Con el hash basta para contar intentos.
-- =====================================================================

CREATE TABLE login_attempts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier_hash CHAR(64)        NOT NULL,   -- sha256(correo normalizado) o sha256(ip)
  kind            ENUM('email','ip') NOT NULL,
  successful      TINYINT(1)      NOT NULL DEFAULT 0,
  attempted_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_attempts_lookup (identifier_hash, attempted_at),
  KEY idx_attempts_purge  (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
