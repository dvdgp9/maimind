-- 004 · Clave de deduplicación en la cola de trabajos.
--
-- Encolar dos veces el mismo trabajo no es un detalle estético: cada
-- transcripción es una llamada de pago, y cada purga borra ficheros. La
-- unicidad la impone el motor y no una comprobación en PHP, porque dos
-- procesos pueden ganar esa comprobación a la vez.
--
-- Se apoya a propósito en que NULL no colisiona dentro de una UNIQUE KEY de
-- MySQL/MariaDB (la misma regla que obligó a usar user_id = 0 en los
-- catálogos, aquí a favor): un trabajo sin clave nunca deduplica, y al
-- terminar se pone la clave a NULL para que el mismo trabajo pueda volver a
-- encolarse mañana.
--
-- La clave es global, no por usuario. Quien encola mete el usuario dentro de
-- la clave cuando lo necesita ('purge_audio:7:2026-08-30').

ALTER TABLE jobs
  ADD COLUMN dedupe_key VARCHAR(120) NULL AFTER type,
  ADD UNIQUE KEY uq_jobs_dedupe (type, dedupe_key);
