-- =====================================================================
-- MaiMind — esquema inicial
--
-- Referencia: docs/design/02-esquema-mysql.md
-- Motor: MariaDB 11.4+ / MySQL 8.0+ · InnoDB · utf8mb4_unicode_ci
--
-- Dos reglas que se repiten en todas las tablas y conviene entender antes
-- de tocar nada:
--
--  1. CHARSET y COLLATE se declaran SIEMPRE de forma explícita. Desde
--     MariaDB 11.4 la collation por defecto de utf8mb4 es uca1400_ai_ci,
--     que no existe en MySQL. Dejarlo al servidor rompería la portabilidad
--     en silencio: mismo SQL, distinto orden y distinta comparación de
--     cadenas según dónde corra.
--
--  2. Los DATETIME van en UTC. `occurred_date` y `local_date` son el día
--     LOCAL del usuario y se calculan en la aplicación. Ver 01-modelo-nucleo §3.
-- =====================================================================


-- =====================================================================
-- 1. IDENTIDAD Y MULTIUSUARIO
-- =====================================================================

CREATE TABLE users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid           CHAR(26)        NOT NULL,
  email         VARCHAR(255)    NOT NULL,
  password_hash VARCHAR(255)    NOT NULL,
  display_name  VARCHAR(120)    NULL,
  timezone      VARCHAR(64)     NOT NULL DEFAULT 'Europe/Madrid',
  locale        VARCHAR(10)     NOT NULL DEFAULT 'es',
  status        ENUM('active','suspended','pending_deletion') NOT NULL DEFAULT 'active',
  settings      JSON            NULL,
  onboarded_at  DATETIME(3)     NULL,
  created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_uid   (uid),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions (
  id           CHAR(64)        NOT NULL,
  user_id      BIGINT UNSIGNED NOT NULL,
  ip_hash      CHAR(64)        NULL,
  user_agent   VARCHAR(255)    NULL,
  expires_at   DATETIME(3)     NOT NULL,
  created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_seen_at DATETIME(3)     NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id, expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE consents (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  kind       ENUM('terms','privacy','ai_processing','audio_retention','sharing','research') NOT NULL,
  version    VARCHAR(20)     NOT NULL,
  granted    TINYINT(1)      NOT NULL,
  evidence   JSON            NULL,
  created_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_consents_user (user_id, kind, created_at),
  CONSTRAINT fk_consents_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compartición con profesionales. Diseñada ahora, sin usar todavía.
-- La caducidad es obligatoria a propósito: un acceso sin fecha de fin
-- es un acceso permanente que nadie recuerda revocar.
CREATE TABLE grants (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject_user_id BIGINT UNSIGNED NOT NULL,
  grantee_user_id BIGINT UNSIGNED NOT NULL,
  role            ENUM('viewer','professional') NOT NULL DEFAULT 'viewer',
  scope           JSON            NOT NULL,
  starts_at       DATETIME(3)     NOT NULL,
  expires_at      DATETIME(3)     NOT NULL,
  revoked_at      DATETIME(3)     NULL,
  consent_id      BIGINT UNSIGNED NULL,
  created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_grants_subject (subject_user_id, expires_at),
  KEY idx_grants_grantee (grantee_user_id, expires_at),
  CONSTRAINT fk_grants_subject FOREIGN KEY (subject_user_id) REFERENCES users(id),
  CONSTRAINT fk_grants_grantee FOREIGN KEY (grantee_user_id) REFERENCES users(id),
  CONSTRAINT fk_grants_consent FOREIGN KEY (consent_id)      REFERENCES consents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sin clave foránea a users: el registro de auditoría debe sobrevivir al
-- borrado de la cuenta que auditó.
CREATE TABLE audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NULL,
  subject_id  BIGINT UNSIGNED NULL,
  action      VARCHAR(60)     NOT NULL,
  target_type VARCHAR(40)     NULL,
  target_id   BIGINT UNSIGNED NULL,
  metadata    JSON            NULL,
  ip_hash     CHAR(64)        NULL,
  created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_audit_subject (subject_id, created_at),
  KEY idx_audit_user    (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 2. NIVEL 1 — CAPTURA CRUDA (inmutable)
-- =====================================================================

CREATE TABLE entries (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid               CHAR(26)        NOT NULL,
  user_id           BIGINT UNSIGNED NOT NULL,
  source            ENUM('audio','text','import','device') NOT NULL DEFAULT 'audio',

  captured_at       DATETIME(3)     NOT NULL,
  received_at       DATETIME(3)     NOT NULL,
  local_date        DATE            NOT NULL,
  client_timezone   VARCHAR(64)     NOT NULL,
  client_utc_offset SMALLINT        NOT NULL,

  audio_path        VARCHAR(512)    NULL,
  audio_bytes       INT UNSIGNED    NULL,
  audio_duration_ms INT UNSIGNED    NULL,
  audio_mime        VARCHAR(80)     NULL,
  audio_sha256      CHAR(64)        NULL,
  audio_state       ENUM('present','purged','never_stored','failed') NOT NULL DEFAULT 'present',
  audio_purge_after DATE            NULL,

  raw_text          MEDIUMTEXT      NULL,
  -- Toque opcional de 1..5 antes de grabar. Es la única señal cuantitativa
  -- del sistema que no pasa por un LLM, y sirve para auditar al extractor.
  mood_hint         TINYINT         NULL,
  mood_hint_at      DATETIME(3)     NULL,

  pipeline_state    ENUM('captured','transcribing','transcribed','extracting',
                         'extracted','reconciled','needs_review','reviewed','failed')
                    NOT NULL DEFAULT 'captured',
  error_message     TEXT            NULL,
  reviewed_at       DATETIME(3)     NULL,

  created_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at        DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_entries_uid (uid),
  KEY idx_entries_user_date  (user_id, local_date),
  KEY idx_entries_user_state (user_id, pipeline_state),
  KEY idx_entries_purge      (audio_state, audio_purge_after),
  CONSTRAINT fk_entries_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT ck_entries_mood CHECK (mood_hint IS NULL OR mood_hint BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transcripts (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_id       BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  provider       VARCHAR(60)     NOT NULL,
  model          VARCHAR(120)    NOT NULL,
  language       VARCHAR(10)     NULL,
  text           MEDIUMTEXT      NOT NULL,
  word_count     INT UNSIGNED    NULL,
  avg_confidence DECIMAL(4,3)    NULL,
  segments       JSON            NULL,
  is_current     TINYINT(1)      NOT NULL DEFAULT 1,
  cost_micros    INT UNSIGNED    NULL,
  latency_ms     INT UNSIGNED    NULL,
  created_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_transcripts_entry (entry_id, is_current),
  KEY idx_transcripts_user  (user_id, created_at),
  CONSTRAINT fk_transcripts_entry FOREIGN KEY (entry_id) REFERENCES entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La propuesta cruda del LLM se guarda entera: permite reprocesar, comparar
-- versiones de prompt y auditar sin volver a pagar la inferencia.
CREATE TABLE extraction_runs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_id       BIGINT UNSIGNED NOT NULL,
  transcript_id  BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  stage          ENUM('extract','resolve','revision_detect') NOT NULL,
  provider       VARCHAR(60)     NOT NULL,
  model          VARCHAR(120)    NOT NULL,
  prompt_version VARCHAR(40)     NOT NULL,
  schema_version VARCHAR(20)     NOT NULL,
  input_tokens   INT UNSIGNED    NULL,
  output_tokens  INT UNSIGNED    NULL,
  cost_micros    INT UNSIGNED    NULL,
  latency_ms     INT UNSIGNED    NULL,
  output_json    JSON            NULL,
  status         ENUM('ok','invalid_json','schema_error','refused','failed') NOT NULL,
  error_message  TEXT            NULL,
  applied_at     DATETIME(3)     NULL,
  superseded_by  BIGINT UNSIGNED NULL,
  created_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_runs_entry (entry_id, stage, created_at),
  KEY idx_runs_user  (user_id, created_at),
  CONSTRAINT fk_runs_entry      FOREIGN KEY (entry_id)      REFERENCES entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_runs_transcript FOREIGN KEY (transcript_id) REFERENCES transcripts(id) ON DELETE CASCADE,
  CONSTRAINT fk_runs_superseded FOREIGN KEY (superseded_by) REFERENCES extraction_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 3. CATÁLOGOS
--
-- user_id = 0 significa "fila universal". No es NULL a propósito: en MySQL
-- y MariaDB, NULL en una columna de una UNIQUE KEY permite duplicados, y
-- estas tablas dependen de esa unicidad. Por eso tampoco llevan clave
-- foránea a users: el 0 no existe en esa tabla.
-- =====================================================================

CREATE TABLE variables (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid              CHAR(26)        NOT NULL,
  user_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  slug             VARCHAR(80)     NOT NULL,
  name             VARCHAR(120)    NOT NULL,
  name_i18n        JSON            NULL,
  definition_i18n  JSON            NULL,
  category         ENUM('emotion','cognition','physical','sleep','behavior','social',
                        'work','leisure','routine','nutrition','environment',
                        'life_event','perception','custom') NOT NULL,
  value_type       ENUM('ordinal','numeric','count','duration','boolean',
                        'categorical','category_intensity') NOT NULL,
  scale_min        DECIMAL(10,3)   NULL,
  scale_max        DECIMAL(10,3)   NULL,
  unit             VARCHAR(30)     NULL,
  polarity         ENUM('higher_better','higher_worse','neutral') NOT NULL DEFAULT 'neutral',
  temporal_kind    ENUM('instant','interval','daily','episodic') NOT NULL DEFAULT 'instant',
  objectivity      ENUM('objective','subjective','inferred','mixed') NOT NULL,
  auto_extractable TINYINT(1)      NOT NULL DEFAULT 1,
  requires_confirm TINYINT(1)      NOT NULL DEFAULT 0,
  is_core          TINYINT(1)      NOT NULL DEFAULT 0,
  status           ENUM('active','candidate','merged','archived') NOT NULL DEFAULT 'candidate',
  merged_into_id   BIGINT UNSIGNED NULL,
  occurrence_count INT UNSIGNED    NOT NULL DEFAULT 0,
  first_seen_at    DATETIME(3)     NULL,
  definition       TEXT            NULL,
  extraction_hint  TEXT            NULL,
  created_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_variables_uid   (uid),
  UNIQUE KEY uq_variables_scope (user_id, slug),
  KEY idx_variables_cat (user_id, category, status),
  CONSTRAINT fk_variables_merged FOREIGN KEY (merged_into_id) REFERENCES variables(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El idioma forma parte de la clave: "triste" y "sad" apuntan a la misma
-- variable pero son alias de idiomas distintos.
CREATE TABLE variable_aliases (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  variable_id BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  lang        VARCHAR(10)     NOT NULL DEFAULT 'es',
  alias       VARCHAR(120)    NOT NULL,
  source      ENUM('seed','ai_suggested','user_defined','merge') NOT NULL,
  created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_alias (user_id, lang, alias),
  KEY idx_alias_var (variable_id),
  CONSTRAINT fk_alias_var FOREIGN KEY (variable_id) REFERENCES variables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE entities (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid            CHAR(26)        NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  type           ENUM('person','place','organization','project','group','object','pet','other') NOT NULL,
  display_name   VARCHAR(120)    NOT NULL,
  -- Permite enseñar patrones a un profesional sin revelar nombres reales.
  pseudonym      VARCHAR(60)     NULL,
  relation_role  VARCHAR(60)     NULL,
  is_sensitive   TINYINT(1)      NOT NULL DEFAULT 0,
  notes          TEXT            NULL,
  mention_count  INT UNSIGNED    NOT NULL DEFAULT 0,
  first_seen_at  DATETIME(3)     NULL,
  last_seen_at   DATETIME(3)     NULL,
  status         ENUM('active','candidate','merged','archived') NOT NULL DEFAULT 'candidate',
  merged_into_id BIGINT UNSIGNED NULL,
  created_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at     DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_entities_uid (uid),
  KEY idx_entities_user (user_id, type, status),
  CONSTRAINT fk_entities_user   FOREIGN KEY (user_id)        REFERENCES users(id),
  CONSTRAINT fk_entities_merged FOREIGN KEY (merged_into_id) REFERENCES entities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE entity_aliases (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_id  BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  alias      VARCHAR(120)    NOT NULL,
  source     ENUM('ai_suggested','user_defined','merge') NOT NULL,
  created_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_entity_alias (user_id, alias),
  KEY idx_entity_alias (entity_id),
  CONSTRAINT fk_ealias_entity FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tags (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  slug        VARCHAR(60)     NOT NULL,
  name        VARCHAR(80)     NOT NULL,
  name_i18n   JSON            NULL,
  kind        ENUM('life_domain','theme','custom') NOT NULL DEFAULT 'theme',
  usage_count INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_tags (user_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 4. NIVEL 2 — DATOS ESTRUCTURADOS (revisables, versionados)
-- =====================================================================

-- Lo narrativo y discreto. La separación entre `fact`, `interpretation` y
-- `thought` es la que materializa el principio "registrar antes de
-- interpretar": "Marta tardó dos horas en contestar" es un hecho,
-- "está pasando de mí" es una interpretación, y van en filas distintas.
CREATE TABLE observations (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid                CHAR(26)        NOT NULL,
  user_id            BIGINT UNSIGNED NOT NULL,
  entry_id           BIGINT UNSIGNED NOT NULL,
  extraction_run_id  BIGINT UNSIGNED NULL,

  kind               ENUM('event','behavior','state','thought','interpretation',
                          'attribution','fact','plan','reflection','note') NOT NULL,
  label              VARCHAR(120)    NOT NULL,
  summary            VARCHAR(500)    NULL,
  verbatim           TEXT            NULL,
  agency             ENUM('self','other','mutual','external','unknown') NULL,
  valence_reported   ENUM('very_negative','negative','neutral','mixed',
                          'positive','very_positive') NULL,
  significance       TINYINT UNSIGNED NULL,
  certainty_reported ENUM('certain','probable','unsure','speculative') NULL,

  occurred_start     DATETIME(3)     NULL,
  occurred_end       DATETIME(3)     NULL,
  occurred_date      DATE            NULL,
  time_precision     ENUM('exact','minute','hour','part_of_day','day','week','month','unknown')
                     NOT NULL DEFAULT 'unknown',
  temporal_scope     ENUM('point','interval','daily_summary','habitual','future','atemporal')
                     NOT NULL DEFAULT 'point',
  duration_seconds   INT UNSIGNED    NULL,
  time_expression    VARCHAR(120)    NULL,
  time_resolution    ENUM('explicit_absolute','explicit_relative','inferred_context',
                          'assumed_recording_time','unknown') NOT NULL DEFAULT 'unknown',
  is_recurring       TINYINT(1)      NOT NULL DEFAULT 0,

  source             ENUM('user_explicit','user_implicit','ai_inferred',
                          'calculated','device','imported') NOT NULL,
  confidence         DECIMAL(3,2)    NULL,
  epistemic_status   ENUM('asserted','inferred','uncertain','user_confirmed',
                          'user_rejected','superseded') NOT NULL DEFAULT 'asserted',
  evidence_quote     VARCHAR(500)    NULL,
  evidence_start     INT UNSIGNED    NULL,
  evidence_end       INT UNSIGNED    NULL,

  lens               ENUM('as_experienced','as_understood') NOT NULL DEFAULT 'as_experienced',
  superseded_by_id   BIGINT UNSIGNED NULL,
  revision_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  attributes         JSON            NULL,
  created_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at         DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_obs_uid (uid),
  KEY idx_obs_user_date (user_id, occurred_date, kind),
  KEY idx_obs_user_kind (user_id, kind, occurred_start),
  KEY idx_obs_entry     (entry_id),
  KEY idx_obs_current   (user_id, superseded_by_id, occurred_date),
  KEY idx_obs_review    (user_id, epistemic_status, created_at),
  CONSTRAINT fk_obs_user       FOREIGN KEY (user_id)           REFERENCES users(id),
  CONSTRAINT fk_obs_entry      FOREIGN KEY (entry_id)          REFERENCES entries(id),
  CONSTRAINT fk_obs_run        FOREIGN KEY (extraction_run_id) REFERENCES extraction_runs(id) ON DELETE SET NULL,
  CONSTRAINT fk_obs_superseded FOREIGN KEY (superseded_by_id)  REFERENCES observations(id) ON DELETE SET NULL,
  CONSTRAINT ck_obs_confidence CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1),
  CONSTRAINT ck_obs_interval   CHECK (occurred_end IS NULL OR occurred_start IS NULL
                                      OR occurred_end >= occurred_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La tabla de trabajo de toda la analítica: (usuario, variable, valor, tiempo).
-- Emociones con intensidad, valoraciones subjetivas, cantidades objetivas y
-- estados sostenidos, todo aquí. Un "estado" es una medición con intervalo;
-- no hace falta una tabla aparte.
CREATE TABLE measurements (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            BIGINT UNSIGNED NOT NULL,
  variable_id        BIGINT UNSIGNED NOT NULL,
  entry_id           BIGINT UNSIGNED NOT NULL,
  extraction_run_id  BIGINT UNSIGNED NULL,
  observation_id     BIGINT UNSIGNED NULL,

  value_num          DECIMAL(12,4)   NULL,
  value_code         VARCHAR(80)     NULL,
  value_bool         TINYINT(1)      NULL,
  value_text         VARCHAR(255)    NULL,
  -- `intensity` (0..100) solo cuando el usuario da un número.
  -- `intensity_band` cuando lo infiere la IA: convertir "bastante nervioso"
  -- en 8.0 fabrica una precisión que nadie ha medido.
  intensity          TINYINT UNSIGNED NULL,
  intensity_band     ENUM('none','slight','moderate','strong','extreme') NULL,
  target_entity_id   BIGINT UNSIGNED NULL,

  occurred_start     DATETIME(3)     NULL,
  occurred_end       DATETIME(3)     NULL,
  occurred_date      DATE            NULL,
  time_precision     ENUM('exact','minute','hour','part_of_day','day','week','month','unknown')
                     NOT NULL DEFAULT 'unknown',
  temporal_scope     ENUM('point','interval','daily_summary','habitual','future','atemporal')
                     NOT NULL DEFAULT 'point',
  duration_seconds   INT UNSIGNED    NULL,
  time_expression    VARCHAR(120)    NULL,
  time_resolution    ENUM('explicit_absolute','explicit_relative','inferred_context',
                          'assumed_recording_time','unknown') NOT NULL DEFAULT 'unknown',

  source             ENUM('user_explicit','user_implicit','ai_inferred',
                          'calculated','device','imported') NOT NULL,
  confidence         DECIMAL(3,2)    NULL,
  epistemic_status   ENUM('asserted','inferred','uncertain','user_confirmed',
                          'user_rejected','superseded') NOT NULL DEFAULT 'asserted',
  certainty_reported ENUM('certain','probable','unsure','speculative') NULL,
  evidence_quote     VARCHAR(500)    NULL,
  evidence_start     INT UNSIGNED    NULL,
  evidence_end       INT UNSIGNED    NULL,

  lens               ENUM('as_experienced','as_understood') NOT NULL DEFAULT 'as_experienced',
  superseded_by_id   BIGINT UNSIGNED NULL,
  revision_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  created_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at         DATETIME(3)     NULL,
  PRIMARY KEY (id),
  KEY idx_m_series   (user_id, variable_id, occurred_date, superseded_by_id),
  KEY idx_m_day      (user_id, occurred_date),
  KEY idx_m_entry    (entry_id),
  KEY idx_m_obs      (observation_id),
  KEY idx_m_intraday (user_id, variable_id, occurred_start),
  KEY idx_m_review   (user_id, epistemic_status, created_at),
  CONSTRAINT fk_m_user       FOREIGN KEY (user_id)           REFERENCES users(id),
  CONSTRAINT fk_m_var        FOREIGN KEY (variable_id)       REFERENCES variables(id),
  CONSTRAINT fk_m_entry      FOREIGN KEY (entry_id)          REFERENCES entries(id),
  CONSTRAINT fk_m_run        FOREIGN KEY (extraction_run_id) REFERENCES extraction_runs(id) ON DELETE SET NULL,
  CONSTRAINT fk_m_obs        FOREIGN KEY (observation_id)    REFERENCES observations(id) ON DELETE SET NULL,
  CONSTRAINT fk_m_target     FOREIGN KEY (target_entity_id)  REFERENCES entities(id) ON DELETE SET NULL,
  CONSTRAINT fk_m_superseded FOREIGN KEY (superseded_by_id)  REFERENCES measurements(id) ON DELETE SET NULL,
  CONSTRAINT ck_m_confidence CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1),
  CONSTRAINT ck_m_intensity  CHECK (intensity IS NULL OR intensity BETWEEN 0 AND 100),
  CONSTRAINT ck_m_interval   CHECK (occurred_end IS NULL OR occurred_start IS NULL
                                    OR occurred_end >= occurred_start),
  -- Una medición sin ningún valor no mide nada. `intensity_band` cuenta:
  -- es la forma normal de guardar lo que infiere la IA.
  CONSTRAINT ck_m_value      CHECK (value_num IS NOT NULL OR value_code IS NOT NULL
                                    OR value_bool IS NOT NULL OR intensity IS NOT NULL
                                    OR intensity_band IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE observation_entities (
  observation_id BIGINT UNSIGNED NOT NULL,
  entity_id      BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  role           ENUM('participant','target','subject','witness','mentioned','location') NOT NULL,
  confidence     DECIMAL(3,2)    NULL,
  PRIMARY KEY (observation_id, entity_id, role),
  KEY idx_oe_entity (user_id, entity_id),
  CONSTRAINT fk_oe_obs    FOREIGN KEY (observation_id) REFERENCES observations(id) ON DELETE CASCADE,
  CONSTRAINT fk_oe_entity FOREIGN KEY (entity_id)      REFERENCES entities(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE observation_tags (
  observation_id BIGINT UNSIGNED NOT NULL,
  tag_id         BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (observation_id, tag_id),
  KEY idx_ot_tag (user_id, tag_id),
  CONSTRAINT fk_ot_obs FOREIGN KEY (observation_id) REFERENCES observations(id) ON DELETE CASCADE,
  CONSTRAINT fk_ot_tag FOREIGN KEY (tag_id)         REFERENCES tags(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relaciones entre observaciones y mediciones. Polimórfica, así que no puede
-- llevar claves foráneas a los extremos: la integridad se cuida en la aplicación.
--
-- No existe una relación `causes` a secas, y es deliberado: el sistema no puede
-- afirmar causalidad. No tener el verbo disponible funciona mejor que acordarse
-- de no usarlo.
--
-- `user_declared` y `same_entry` son los dos campos que salvan la analítica:
-- un hallazgo solo cuenta como observacional si ambos están a 0. Sin ellos, el
-- motor redescubre las opiniones del usuario y se las devuelve como si fueran
-- suyas.
CREATE TABLE links (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  from_type     ENUM('observation','measurement') NOT NULL,
  from_id       BIGINT UNSIGNED NOT NULL,
  to_type       ENUM('observation','measurement') NOT NULL,
  to_id         BIGINT UNSIGNED NOT NULL,
  relation      ENUM('precedes','follows','co_occurs','overlaps','part_of',
                     'about','elaborates','contradicts','similar_to',
                     'revises','reinterprets','confirms','rejects',
                     'user_claims_caused','user_claims_caused_by','response_to') NOT NULL,
  lag_seconds   INT             NULL,
  asserted_by   ENUM('user','ai','system') NOT NULL,
  user_declared TINYINT(1)      NOT NULL DEFAULT 0,
  same_entry    TINYINT(1)      NOT NULL DEFAULT 0,
  confidence    DECIMAL(3,2)    NULL,
  entry_id      BIGINT UNSIGNED NULL,
  created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_link (from_type, from_id, to_type, to_id, relation),
  KEY idx_link_from  (user_id, from_type, from_id),
  KEY idx_link_to    (user_id, to_type, to_id),
  KEY idx_link_clean (user_id, relation, user_declared, same_entry),
  CONSTRAINT fk_links_user  FOREIGN KEY (user_id)  REFERENCES users(id),
  CONSTRAINT fk_links_entry FOREIGN KEY (entry_id) REFERENCES entries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial. También polimórfica.
--
-- `correction` muta la fila vigente y guarda aquí el estado anterior.
-- `reinterpretation` NO muta nada: inserta una fila nueva con lens='as_understood'
-- y marca la original como superseded. Ambas versiones son verdad, y la
-- diferencia entre ellas es analizable.
CREATE TABLE revisions (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id               BIGINT UNSIGNED NOT NULL,
  target_type           ENUM('observation','measurement','entity','variable','link') NOT NULL,
  target_id             BIGINT UNSIGNED NOT NULL,
  revision_type         ENUM('correction','refinement','reinterpretation','confirmation',
                             'rejection','retraction','merge') NOT NULL,
  changed_fields        JSON            NULL,
  before_snapshot       JSON            NULL,
  reason                VARCHAR(300)    NULL,
  actor                 ENUM('user','ai','system') NOT NULL,
  triggered_by_entry_id BIGINT UNSIGNED NULL,
  extraction_run_id     BIGINT UNSIGNED NULL,
  confidence            DECIMAL(3,2)    NULL,
  created_at            DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_rev_target (target_type, target_id, created_at),
  KEY idx_rev_user   (user_id, revision_type, created_at),
  CONSTRAINT fk_rev_user  FOREIGN KEY (user_id)               REFERENCES users(id),
  CONSTRAINT fk_rev_entry FOREIGN KEY (triggered_by_entry_id) REFERENCES entries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 5. NIVEL 3 — DERIVADOS
--
-- Todo lo de aquí se puede borrar entero y regenerar desde el nivel 2.
-- Si algo no se puede regenerar, es un bug de diseño.
-- =====================================================================

-- El silencio es dato. La gente deja de grabar justo cuando está peor, así que
-- tratar los huecos como "sin datos" sesga todas las medias hacia los días con
-- energía para hablar. Esta tabla los hace visibles y analizables.
CREATE TABLE day_coverage (
  user_id        BIGINT UNSIGNED NOT NULL,
  local_date     DATE            NOT NULL,
  entries_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  audio_seconds  INT UNSIGNED    NOT NULL DEFAULT 0,
  words_total    INT UNSIGNED    NOT NULL DEFAULT 0,
  first_entry_at DATETIME(3)     NULL,
  last_entry_at  DATETIME(3)     NULL,
  is_gap         TINYINT(1)      NOT NULL DEFAULT 1,
  gap_run_length SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  computed_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id, local_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE daily_metrics (
  user_id         BIGINT UNSIGNED NOT NULL,
  local_date      DATE            NOT NULL,
  variable_id     BIGINT UNSIGNED NOT NULL,
  lens            ENUM('as_experienced','as_understood') NOT NULL DEFAULT 'as_understood',
  n               SMALLINT UNSIGNED NOT NULL,
  n_confirmed     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  n_inferred      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  value_mean      DECIMAL(12,4)   NULL,
  value_median    DECIMAL(12,4)   NULL,
  value_min       DECIMAL(12,4)   NULL,
  value_max       DECIMAL(12,4)   NULL,
  value_sum       DECIMAL(14,4)   NULL,
  value_last      DECIMAL(12,4)   NULL,
  minutes_covered SMALLINT UNSIGNED NULL,
  quality         ENUM('good','partial','sparse') NOT NULL DEFAULT 'sparse',
  algo_version    VARCHAR(20)     NOT NULL,
  computed_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id, local_date, variable_id, lens),
  KEY idx_dm_var (user_id, variable_id, local_date),
  CONSTRAINT fk_dm_var FOREIGN KEY (variable_id) REFERENCES variables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mediana y MAD, no media y desviación típica: estos datos tienen valores
-- extremos reales y distribuciones asimétricas, y la media es frágil ante eso.
-- `status='insufficient_data'` es un estado de primera clase: sin datos
-- suficientes no hay línea base, y la interfaz debe decirlo en vez de dibujar
-- una gráfica con aspecto de conclusión.
CREATE TABLE baselines (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  variable_id  BIGINT UNSIGNED NOT NULL,
  window_kind  ENUM('rolling_30','rolling_90','all_time','seasonal') NOT NULL,
  valid_from   DATE            NOT NULL,
  valid_to     DATE            NOT NULL,
  n            INT UNSIGNED    NOT NULL,
  median       DECIMAL(12,4)   NULL,
  mad          DECIMAL(12,4)   NULL,
  p10          DECIMAL(12,4)   NULL,
  p90          DECIMAL(12,4)   NULL,
  status       ENUM('valid','insufficient_data') NOT NULL DEFAULT 'insufficient_data',
  algo_version VARCHAR(20)     NOT NULL,
  computed_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_baseline (user_id, variable_id, window_kind, valid_to),
  KEY idx_baseline_lookup (user_id, variable_id, window_kind),
  CONSTRAINT fk_baseline_var FOREIGN KEY (variable_id) REFERENCES variables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- `registered_at` es el campo clave: una hipótesis exploratoria solo se evalúa
-- contra datos POSTERIORES a su formulación. Es validación fuera de muestra y
-- la única defensa honesta contra el dragado de datos.
CREATE TABLE hypotheses (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid               CHAR(26)        NOT NULL,
  user_id           BIGINT UNSIGNED NOT NULL,
  origin            ENUM('user_stated','system_exploratory') NOT NULL,
  statement         VARCHAR(500)    NOT NULL,
  spec              JSON            NOT NULL,
  registered_at     DATETIME(3)     NOT NULL,
  status            ENUM('registered','collecting','evaluable','supported',
                         'not_supported','inconclusive','retired') NOT NULL DEFAULT 'registered',
  min_n_required    INT UNSIGNED    NOT NULL,
  n_current         INT UNSIGNED    NOT NULL DEFAULT 0,
  effect_size       DECIMAL(6,3)    NULL,
  effect_ci_low     DECIMAL(6,3)    NULL,
  effect_ci_high    DECIMAL(6,3)    NULL,
  evidence_for      JSON            NULL,
  evidence_against  JSON            NULL,
  confounders       JSON            NULL,
  algo_version      VARCHAR(20)     NOT NULL,
  last_evaluated_at DATETIME(3)     NULL,
  created_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_hyp_uid (uid),
  KEY idx_hyp_user (user_id, status),
  CONSTRAINT fk_hyp_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los embeddings complementan el modelo estructurado, no lo sustituyen: sirven
-- para recuperar contexto parecido, nunca para calcular métricas.
CREATE TABLE embeddings (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  owner_type ENUM('transcript','observation','entry_summary') NOT NULL,
  owner_id   BIGINT UNSIGNED NOT NULL,
  model      VARCHAR(120)    NOT NULL,
  dim        SMALLINT UNSIGNED NOT NULL,
  -- No se llama `vector`: es palabra reservada desde MariaDB 11.7.
  embedding  BLOB            NOT NULL,
  -- Precalculada para acelerar el coseno.
  norm       FLOAT           NOT NULL,
  created_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_emb (owner_type, owner_id, model),
  KEY idx_emb_user (user_id, owner_type),
  CONSTRAINT fk_emb_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 6. INFRAESTRUCTURA
-- =====================================================================

-- Cola de trabajos sin Redis. El reclamo seguro bajo concurrencia es:
--   SELECT id FROM jobs WHERE state='pending' AND run_after <= NOW(3)
--    ORDER BY priority, id LIMIT 1 FOR UPDATE SKIP LOCKED;
CREATE TABLE jobs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NULL,
  type         VARCHAR(60)     NOT NULL,
  payload      JSON            NOT NULL,
  state        ENUM('pending','running','done','failed','dead') NOT NULL DEFAULT 'pending',
  priority     TINYINT         NOT NULL DEFAULT 5,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  run_after    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  locked_by    VARCHAR(64)     NULL,
  locked_at    DATETIME(3)     NULL,
  last_error   TEXT            NULL,
  created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  finished_at  DATETIME(3)     NULL,
  PRIMARY KEY (id),
  KEY idx_jobs_claim (state, run_after, priority),
  KEY idx_jobs_user  (user_id, type, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
