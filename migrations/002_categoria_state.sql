-- =====================================================================
-- Añade la categoría `state` al catálogo de variables.
--
-- El ánimo, la energía y el estrés no son emociones: son estados de fondo
-- continuos que se miden a diario y forman el eje de todas las series
-- temporales. La tristeza o el enfado son episodios que se enganchan a
-- acontecimientos concretos.
--
-- Meterlos en la misma categoría daría una interfaz con dieciocho
-- "emociones" de dos naturalezas distintas, y una analítica que trata igual
-- una línea continua y un suceso puntual.
-- =====================================================================

ALTER TABLE variables
  MODIFY COLUMN category ENUM('state','emotion','cognition','physical','sleep',
                              'behavior','social','work','leisure','routine',
                              'nutrition','environment','life_event','perception',
                              'custom') NOT NULL;
