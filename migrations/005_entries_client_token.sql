-- 005 · Testigo de cliente para que subir una grabación sea idempotente.
--
-- Lo pide la cola sin conexión (tarea 1.4). Una cola que reintenta sin un
-- testigo estable produce duplicados en cuanto una respuesta se pierde por el
-- camino: el servidor guardó la entrada, el móvil no llegó a enterarse, y al
-- recuperar la red la vuelve a subir. Nadie se daría cuenta —son dos filas
-- plausibles— y a los seis meses esa grabación contaría dos veces en todas las
-- medias. En una base longitudinal, un duplicado silencioso es peor que un
-- error ruidoso.
--
-- El testigo lo genera el cliente al terminar de grabar y lo conserva mientras
-- la grabación siga en su cola, así que sobrevive a reintentos, a cierres de la
-- aplicación y a reinicios del teléfono.
--
-- Es único POR USUARIO y no en toda la tabla: viene de fuera, y un testigo
-- repetido por otra persona no puede tapar la grabación de nadie.

ALTER TABLE entries
  ADD COLUMN client_token VARCHAR(64) NULL AFTER source,
  ADD UNIQUE KEY uq_entries_client_token (user_id, client_token);
