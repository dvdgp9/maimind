-- 006 · Huecos de cobertura en la transcripción.
--
-- Existe por un hallazgo del 2026-08-30: whisper-large-v3 y su variante turbo
-- se comieron una frase entera de una grabación de 40 s, en silencio y de forma
-- determinista. El texto resultante se leía con total fluidez; nada delataba la
-- pérdida. Ver docs/api/openrouter.md §1.
--
-- Lo único que la delataba eran los tramos: un hueco de 4,6 s en la línea de
-- tiempo entre el final de uno y el principio del siguiente. Estas dos columnas
-- convierten esa señal en un dato consultable.
--
-- `gap_total_ms` es la columna que se consulta —cuánto audio no está
-- representado en el texto— y `coverage_gaps` guarda dónde, como JSON opaco.
--
-- NULL en las dos significa **no se sabe**, no «no hay huecos»: es lo que pasa
-- cuando el proveedor no devuelve tramos. Distinguirlo importa; decir que no
-- hay pérdida cuando no se ha podido mirar sería justo el tipo de mentira que
-- este sistema no puede permitirse.

ALTER TABLE transcripts
  ADD COLUMN gap_total_ms  INT UNSIGNED NULL AFTER avg_confidence,
  ADD COLUMN coverage_gaps JSON         NULL AFTER gap_total_ms;
