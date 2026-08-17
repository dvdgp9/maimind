# 02 — Esquema de base de datos

Objetivo: **MariaDB 11.4**, InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`.
Todos los `DATETIME` en **UTC**. `occurred_date` es el día **local** del usuario.

> **Por qué MariaDB.** El servidor de producción corre MariaDB 11.4.10 sobre HestiaCP
> (verificado 2026-08-16). Instalar MySQL 8 en paralelo obligaría a pelearse con el panel y
> a duplicar memoria en una máquina de 3,7 GB compartida con otras aplicaciones. El coste de
> adaptarse es prácticamente nulo, por la razón que explica el bloque siguiente.

## 0. Compatibilidad — por qué esto funciona igual

Todo lo que usa este esquema existe en MariaDB desde la 10.6:

| Característica | Desde | Uso aquí |
|---|---|---|
| `FOR UPDATE ... SKIP LOCKED` | 10.6 | Reclamo de jobs sin Redis |
| Restricciones `CHECK` | 10.2 | Validación de valores |
| Funciones de ventana y CTEs | 10.2 | Analítica |
| `DATETIME(3)` + `ON UPDATE` | — | Sellos temporales |
| Columnas generadas + índice | 10.2 | Escape si algún día hay que indexar JSON |

**La diferencia real** es que en MariaDB el tipo `JSON` es un alias de `LONGTEXT` con un
`CHECK (json_valid(...))` automático, no un tipo binario nativo. Consecuencias: ocupa algo
más y **no hay índices multivalor**.

No nos afecta, porque el diseño ya trataba el JSON como **almacenamiento opaco**:
`before_snapshot`, `output_json`, `payload`, `settings`, `spec` y `attributes` se escriben y
se leen enteros, y **ninguna consulta indexa dentro de ellos**. Todo lo que hay que filtrar,
ordenar o agregar vive en columnas propias. Esa decisión se tomó por higiene de modelado; el
efecto secundario es que el esquema es portable entre MySQL y MariaDB sin tocar una línea.

**En local: MariaDB 11.4 en Docker**, misma versión que producción.

Convenciones:
- PK `BIGINT UNSIGNED AUTO_INCREMENT`; `uid CHAR(26)` (ULID) donde el objeto se expone en URL.
- `user_id` en toda tabla de datos, siempre primer campo de los índices compuestos.
- `user_id = 0` en catálogos = fila universal (evita `NULL` en claves únicas).
- Borrado en dos fases: `deleted_at` (papelera, 30 días) → purga física por job.

---

## 1. Identidad y multiusuario

```sql
CREATE TABLE users (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid             CHAR(26)        NOT NULL,
  email           VARCHAR(255)    NOT NULL,
  password_hash   VARCHAR(255)    NOT NULL,
  display_name    VARCHAR(120)    NULL,
  timezone        VARCHAR(64)     NOT NULL DEFAULT 'Europe/Madrid',
  locale          VARCHAR(10)     NOT NULL DEFAULT 'es-ES',
  status          ENUM('active','suspended','pending_deletion') NOT NULL DEFAULT 'active',
  settings        JSON            NULL,      -- retención de audio, privacidad, UI
  onboarded_at    DATETIME(3)     NULL,
  created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at      DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_uid   (uid),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE sessions (
  id            CHAR(64)        NOT NULL,          -- token hasheado
  user_id       BIGINT UNSIGNED NOT NULL,
  ip_hash       CHAR(64)        NULL,
  user_agent    VARCHAR(255)    NULL,
  expires_at    DATETIME(3)     NOT NULL,
  created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_seen_at  DATETIME(3)     NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id, expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Compartición con profesionales. Diseñado ahora, implementado más adelante.
CREATE TABLE grants (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject_user_id BIGINT UNSIGNED NOT NULL,       -- dueño de los datos
  grantee_user_id BIGINT UNSIGNED NOT NULL,       -- quien recibe acceso
  role            ENUM('viewer','professional') NOT NULL DEFAULT 'viewer',
  scope           JSON            NOT NULL,        -- variables, rango de fechas, niveles
  starts_at       DATETIME(3)     NOT NULL,
  expires_at      DATETIME(3)     NOT NULL,        -- caducidad obligatoria
  revoked_at      DATETIME(3)     NULL,
  consent_id      BIGINT UNSIGNED NULL,
  created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_grants_subject (subject_user_id, expires_at),
  KEY idx_grants_grantee (grantee_user_id, expires_at)
) ENGINE=InnoDB;

CREATE TABLE consents (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  kind        ENUM('terms','privacy','ai_processing','audio_retention','sharing','research') NOT NULL,
  version     VARCHAR(20)     NOT NULL,
  granted     TINYINT(1)      NOT NULL,
  evidence    JSON            NULL,               -- IP hash, UA, timestamp del click
  created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_consents_user (user_id, kind, created_at)
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NULL,               -- quién actuó
  subject_id  BIGINT UNSIGNED NULL,               -- sobre los datos de quién
  action      VARCHAR(60)     NOT NULL,
  target_type VARCHAR(40)     NULL,
  target_id   BIGINT UNSIGNED NULL,
  metadata    JSON            NULL,
  ip_hash     CHAR(64)        NULL,
  created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_audit_subject (subject_id, created_at),
  KEY idx_audit_user    (user_id, created_at)
) ENGINE=InnoDB;
```

---

## 2. Nivel 1 — Captura cruda

```sql
CREATE TABLE entries (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid                CHAR(26)        NOT NULL,
  user_id            BIGINT UNSIGNED NOT NULL,
  source             ENUM('audio','text','import','device') NOT NULL DEFAULT 'audio',

  captured_at        DATETIME(3)     NOT NULL,     -- reloj del cliente, en UTC
  received_at        DATETIME(3)     NOT NULL,     -- reloj del servidor
  local_date         DATE            NOT NULL,     -- día local del usuario
  client_timezone    VARCHAR(64)     NOT NULL,
  client_utc_offset  SMALLINT        NOT NULL,     -- minutos, capta DST y viajes

  audio_path         VARCHAR(512)    NULL,
  audio_bytes        INT UNSIGNED    NULL,
  audio_duration_ms  INT UNSIGNED    NULL,
  audio_mime         VARCHAR(80)     NULL,
  audio_sha256       CHAR(64)        NULL,
  audio_state        ENUM('present','purged','never_stored','failed') NOT NULL DEFAULT 'present',
  audio_purge_after  DATE            NULL,

  raw_text           MEDIUMTEXT      NULL,         -- si source='text'
  mood_hint          TINYINT         NULL,         -- 1..5, toque opcional pre-grabación
  mood_hint_at       DATETIME(3)     NULL,

  pipeline_state     ENUM('captured','transcribing','transcribed','extracting',
                          'extracted','reconciled','needs_review','reviewed','failed')
                     NOT NULL DEFAULT 'captured',
  error_message      TEXT            NULL,
  reviewed_at        DATETIME(3)     NULL,

  created_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at         DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_entries_uid (uid),
  KEY idx_entries_user_date  (user_id, local_date),
  KEY idx_entries_user_state (user_id, pipeline_state),
  KEY idx_entries_purge      (audio_state, audio_purge_after),
  CONSTRAINT fk_entries_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT ck_entries_mood CHECK (mood_hint IS NULL OR mood_hint BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE transcripts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_id        BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  provider        VARCHAR(60)     NOT NULL,
  model           VARCHAR(120)    NOT NULL,
  language        VARCHAR(10)     NULL,
  text            MEDIUMTEXT      NOT NULL,
  word_count      INT UNSIGNED    NULL,
  avg_confidence  DECIMAL(4,3)    NULL,
  segments        JSON            NULL,           -- timestamps por segmento si los da
  is_current      TINYINT(1)      NOT NULL DEFAULT 1,
  cost_micros     INT UNSIGNED    NULL,
  latency_ms      INT UNSIGNED    NULL,
  created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_transcripts_entry (entry_id, is_current),
  KEY idx_transcripts_user  (user_id, created_at),
  CONSTRAINT fk_transcripts_entry FOREIGN KEY (entry_id) REFERENCES entries(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Cada pasada del LLM. La propuesta se guarda cruda: reproducibilidad y comparación
-- entre versiones de prompt sin volver a pagar la inferencia.
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
  CONSTRAINT fk_runs_entry FOREIGN KEY (entry_id) REFERENCES entries(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

## 3. Catálogos

```sql
CREATE TABLE variables (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid               CHAR(26)        NOT NULL,
  user_id           BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- 0 = catálogo universal
  slug              VARCHAR(80)     NOT NULL,             -- 'emotion.sadness' — identificador, nunca se traduce
  name              VARCHAR(120)    NOT NULL,             -- etiqueta en el idioma base del catálogo
  name_i18n         JSON            NULL,                 -- {"es":"Tristeza","en":"Sadness"}
  definition_i18n   JSON            NULL,
  category          ENUM('emotion','cognition','physical','sleep','behavior','social',
                         'work','leisure','routine','nutrition','environment',
                         'life_event','perception','custom') NOT NULL,
  value_type        ENUM('ordinal','numeric','count','duration','boolean',
                         'categorical','category_intensity') NOT NULL,
  scale_min         DECIMAL(10,3)   NULL,
  scale_max         DECIMAL(10,3)   NULL,
  unit              VARCHAR(30)     NULL,
  polarity          ENUM('higher_better','higher_worse','neutral') NOT NULL DEFAULT 'neutral',
  temporal_kind     ENUM('instant','interval','daily','episodic') NOT NULL DEFAULT 'instant',
  objectivity       ENUM('objective','subjective','inferred','mixed') NOT NULL,
  auto_extractable  TINYINT(1)      NOT NULL DEFAULT 1,
  requires_confirm  TINYINT(1)      NOT NULL DEFAULT 0,
  is_core           TINYINT(1)      NOT NULL DEFAULT 0,
  status            ENUM('active','candidate','merged','archived') NOT NULL DEFAULT 'candidate',
  merged_into_id    BIGINT UNSIGNED NULL,
  occurrence_count  INT UNSIGNED    NOT NULL DEFAULT 0,   -- para promoción de candidatas
  first_seen_at     DATETIME(3)     NULL,
  definition        TEXT            NULL,
  extraction_hint   TEXT            NULL,                  -- se inyecta en el prompt
  created_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_variables_scope (user_id, slug),
  KEY idx_variables_cat (user_id, category, status)
) ENGINE=InnoDB;

CREATE TABLE variable_aliases (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  variable_id  BIGINT UNSIGNED NOT NULL,
  user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  lang         VARCHAR(10)     NOT NULL DEFAULT 'es',   -- "triste"/"sad" → misma variable
  alias        VARCHAR(120)    NOT NULL,
  source       ENUM('seed','ai_suggested','user_defined','merge') NOT NULL,
  created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_alias (user_id, lang, alias),
  KEY idx_alias_var (variable_id),
  CONSTRAINT fk_alias_var FOREIGN KEY (variable_id) REFERENCES variables(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE entities (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid            CHAR(26)        NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  type           ENUM('person','place','organization','project','group','object','pet','other') NOT NULL,
  display_name   VARCHAR(120)    NOT NULL,
  pseudonym      VARCHAR(60)     NULL,          -- para compartir sin exponer nombres
  relation_role  VARCHAR(60)     NULL,          -- 'pareja','madre','jefe','amiga'
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
  KEY idx_entities_user (user_id, type, status)
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;
```

---

## 4. Nivel 2 — Datos estructurados

### 4.1 `observations`

```sql
CREATE TABLE observations (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid                CHAR(26)        NOT NULL,
  user_id            BIGINT UNSIGNED NOT NULL,
  entry_id           BIGINT UNSIGNED NOT NULL,
  extraction_run_id  BIGINT UNSIGNED NULL,

  kind               ENUM('event','behavior','state','thought','interpretation',
                          'attribution','fact','plan','reflection','note') NOT NULL,
  label              VARCHAR(120)    NOT NULL,      -- etiqueta corta normalizada
  summary            VARCHAR(500)    NULL,          -- resumen neutro
  verbatim           TEXT            NULL,          -- literal, cuando el texto ES el dato
  agency             ENUM('self','other','mutual','external','unknown') NULL,
  valence_reported   ENUM('very_negative','negative','neutral','mixed',
                          'positive','very_positive') NULL,
  significance       TINYINT UNSIGNED NULL,         -- 0..100, importancia declarada
  certainty_reported ENUM('certain','probable','unsure','speculative') NULL,

  -- temporal (ver doc 01 §3)
  occurred_start     DATETIME(3)     NULL,
  occurred_end       DATETIME(3)     NULL,
  occurred_date      DATE            NULL,          -- día LOCAL
  time_precision     ENUM('exact','minute','hour','part_of_day','day','week','month','unknown')
                     NOT NULL DEFAULT 'unknown',
  temporal_scope     ENUM('point','interval','daily_summary','habitual','future','atemporal')
                     NOT NULL DEFAULT 'point',
  duration_seconds   INT UNSIGNED    NULL,
  time_expression    VARCHAR(120)    NULL,
  time_resolution    ENUM('explicit_absolute','explicit_relative','inferred_context',
                          'assumed_recording_time','unknown') NOT NULL DEFAULT 'unknown',
  is_recurring       TINYINT(1)      NOT NULL DEFAULT 0,

  -- evidencia (ver doc 01 §4)
  source             ENUM('user_explicit','user_implicit','ai_inferred',
                          'calculated','device','imported') NOT NULL,
  confidence         DECIMAL(3,2)    NULL,
  epistemic_status   ENUM('asserted','inferred','uncertain','user_confirmed',
                          'user_rejected','superseded') NOT NULL DEFAULT 'asserted',
  evidence_quote     VARCHAR(500)    NULL,
  evidence_start     INT UNSIGNED    NULL,
  evidence_end       INT UNSIGNED    NULL,

  -- versionado (ver doc 01 §5)
  lens               ENUM('as_experienced','as_understood') NOT NULL DEFAULT 'as_experienced',
  superseded_by_id   BIGINT UNSIGNED NULL,
  revision_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  attributes         JSON            NULL,          -- cola larga, no indexada
  created_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at         DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_obs_uid (uid),
  KEY idx_obs_user_date  (user_id, occurred_date, kind),
  KEY idx_obs_user_kind  (user_id, kind, occurred_start),
  KEY idx_obs_entry      (entry_id),
  KEY idx_obs_current    (user_id, superseded_by_id, occurred_date),
  KEY idx_obs_review     (user_id, epistemic_status, created_at),
  CONSTRAINT fk_obs_user  FOREIGN KEY (user_id)  REFERENCES users(id),
  CONSTRAINT fk_obs_entry FOREIGN KEY (entry_id) REFERENCES entries(id)
) ENGINE=InnoDB;
```

### 4.2 `measurements` — la tabla de trabajo de la analítica

```sql
CREATE TABLE measurements (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            BIGINT UNSIGNED NOT NULL,
  variable_id        BIGINT UNSIGNED NOT NULL,
  entry_id           BIGINT UNSIGNED NOT NULL,
  extraction_run_id  BIGINT UNSIGNED NULL,
  observation_id     BIGINT UNSIGNED NULL,          -- si cuelga de un episodio

  value_num          DECIMAL(12,4)   NULL,
  value_code         VARCHAR(80)     NULL,          -- categóricas
  value_bool         TINYINT(1)      NULL,
  value_text         VARCHAR(255)    NULL,          -- literal del usuario
  intensity          TINYINT UNSIGNED NULL,         -- 0..100 normalizado
  intensity_band     ENUM('none','slight','moderate','strong','extreme') NULL,
  target_entity_id   BIGINT UNSIGNED NULL,          -- "enfadado CON X" / "conmigo mismo"

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
  CONSTRAINT fk_m_user FOREIGN KEY (user_id)     REFERENCES users(id),
  CONSTRAINT fk_m_var  FOREIGN KEY (variable_id) REFERENCES variables(id),
  CONSTRAINT fk_m_entry FOREIGN KEY (entry_id)   REFERENCES entries(id),
  -- `intensity_band` cuenta como valor: es la forma normal de guardar lo que
  -- infiere la IA (ver 03-extraccion.md R3). Sin ella en el CHECK, una medición
  -- legítima como "bastante nervioso" sería rechazada.
  CONSTRAINT ck_m_value CHECK (
    value_num IS NOT NULL OR value_code IS NOT NULL OR
    value_bool IS NOT NULL OR intensity IS NOT NULL OR
    intensity_band IS NOT NULL
  )
) ENGINE=InnoDB;
```

> **Sobre la duplicación de columnas temporales y de evidencia entre las dos tablas:**
> es deliberada. La alternativa (una tabla `facts` común con herencia) obliga a un JOIN en
> absolutamente toda consulta analítica y complica los índices. Duplicar ~16 columnas en dos
> tablas es el precio correcto por tener dos tablas planas y rápidas. Se mitiga con un trait
> compartido en PHP que serializa/deserializa el bloque temporal y el de evidencia.

### 4.3 Relaciones, adjuntos y revisiones

```sql
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
) ENGINE=InnoDB;

CREATE TABLE observation_tags (
  observation_id BIGINT UNSIGNED NOT NULL,
  tag_id         BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (observation_id, tag_id),
  KEY idx_ot_tag (user_id, tag_id),
  CONSTRAINT fk_ot_obs FOREIGN KEY (observation_id) REFERENCES observations(id) ON DELETE CASCADE,
  CONSTRAINT fk_ot_tag FOREIGN KEY (tag_id)         REFERENCES tags(id)         ON DELETE CASCADE
) ENGINE=InnoDB;

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
  user_declared TINYINT(1)      NOT NULL DEFAULT 0,   -- lo afirmó el usuario
  same_entry    TINYINT(1)      NOT NULL DEFAULT 0,   -- circularidad: misma grabación
  confidence    DECIMAL(3,2)    NULL,
  entry_id      BIGINT UNSIGNED NULL,
  created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_link (from_type, from_id, to_type, to_id, relation),
  KEY idx_link_from (user_id, from_type, from_id),
  KEY idx_link_to   (user_id, to_type, to_id),
  KEY idx_link_clean (user_id, relation, user_declared, same_entry)
) ENGINE=InnoDB;

CREATE TABLE revisions (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id               BIGINT UNSIGNED NOT NULL,
  target_type           ENUM('observation','measurement','entity','variable','link') NOT NULL,
  target_id             BIGINT UNSIGNED NOT NULL,
  revision_type         ENUM('correction','refinement','reinterpretation','confirmation',
                             'rejection','retraction','merge') NOT NULL,
  changed_fields        JSON            NULL,    -- {"intensity":{"from":80,"to":40}}
  before_snapshot       JSON            NULL,    -- fila completa previa
  reason                VARCHAR(300)    NULL,
  actor                 ENUM('user','ai','system') NOT NULL,
  triggered_by_entry_id BIGINT UNSIGNED NULL,    -- qué grabación provocó la revisión
  extraction_run_id     BIGINT UNSIGNED NULL,
  confidence            DECIMAL(3,2)    NULL,
  created_at            DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_rev_target (target_type, target_id, created_at),
  KEY idx_rev_user   (user_id, revision_type, created_at)
) ENGINE=InnoDB;
```

---

## 5. Nivel 3 — Derivados (todo recalculable)

```sql
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
) ENGINE=InnoDB;

CREATE TABLE daily_metrics (
  user_id       BIGINT UNSIGNED NOT NULL,
  local_date    DATE            NOT NULL,
  variable_id   BIGINT UNSIGNED NOT NULL,
  lens          ENUM('as_experienced','as_understood') NOT NULL DEFAULT 'as_understood',
  n             SMALLINT UNSIGNED NOT NULL,
  n_confirmed   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  n_inferred    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  value_mean    DECIMAL(12,4)   NULL,
  value_median  DECIMAL(12,4)   NULL,
  value_min     DECIMAL(12,4)   NULL,
  value_max     DECIMAL(12,4)   NULL,
  value_sum     DECIMAL(14,4)   NULL,
  value_last    DECIMAL(12,4)   NULL,
  minutes_covered SMALLINT UNSIGNED NULL,
  quality       ENUM('good','partial','sparse') NOT NULL DEFAULT 'sparse',
  algo_version  VARCHAR(20)     NOT NULL,
  computed_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id, local_date, variable_id, lens),
  KEY idx_dm_var (user_id, variable_id, local_date)
) ENGINE=InnoDB;

CREATE TABLE baselines (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  variable_id   BIGINT UNSIGNED NOT NULL,
  window_kind   ENUM('rolling_30','rolling_90','all_time','seasonal') NOT NULL,
  valid_from    DATE            NOT NULL,
  valid_to      DATE            NOT NULL,
  n             INT UNSIGNED    NOT NULL,
  median        DECIMAL(12,4)   NULL,
  mad           DECIMAL(12,4)   NULL,       -- robusto, no desviación típica
  p10           DECIMAL(12,4)   NULL,
  p90           DECIMAL(12,4)   NULL,
  status        ENUM('valid','insufficient_data') NOT NULL DEFAULT 'insufficient_data',
  algo_version  VARCHAR(20)     NOT NULL,
  computed_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_baseline (user_id, variable_id, window_kind, valid_to),
  KEY idx_baseline_lookup (user_id, variable_id, window_kind)
) ENGINE=InnoDB;

CREATE TABLE hypotheses (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid               CHAR(26)        NOT NULL,
  user_id           BIGINT UNSIGNED NOT NULL,
  origin            ENUM('user_stated','system_exploratory') NOT NULL,
  statement         VARCHAR(500)    NOT NULL,
  spec              JSON            NOT NULL,   -- variables, ventana, comparación
  registered_at     DATETIME(3)     NOT NULL,   -- clave: la validación usa datos POSTERIORES
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
  KEY idx_hyp_user (user_id, status)
) ENGINE=InnoDB;

CREATE TABLE embeddings (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  owner_type  ENUM('transcript','observation','entry_summary') NOT NULL,
  owner_id    BIGINT UNSIGNED NOT NULL,
  model       VARCHAR(120)    NOT NULL,
  dim         SMALLINT UNSIGNED NOT NULL,
  -- No se llama `vector`: es palabra reservada desde MariaDB 11.7.
  embedding   BLOB            NOT NULL,   -- float32 empaquetado
  norm        FLOAT           NOT NULL,   -- precalculada, acelera el coseno
  created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_emb (owner_type, owner_id, model),
  KEY idx_emb_user (user_id, owner_type)
) ENGINE=InnoDB;
```

---

## 6. Infraestructura

```sql
CREATE TABLE jobs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NULL,
  type          VARCHAR(60)     NOT NULL,   -- transcribe, extract, reconcile, rollup...
  payload       JSON            NOT NULL,
  state         ENUM('pending','running','done','failed','dead') NOT NULL DEFAULT 'pending',
  priority      TINYINT         NOT NULL DEFAULT 5,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 5,
  run_after     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  locked_by     VARCHAR(64)     NULL,
  locked_at     DATETIME(3)     NULL,
  last_error    TEXT            NULL,
  created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  finished_at   DATETIME(3)     NULL,
  PRIMARY KEY (id),
  KEY idx_jobs_claim (state, run_after, priority),
  KEY idx_jobs_user  (user_id, type, state)
) ENGINE=InnoDB;

-- La crea bin/migrate por sí mismo antes de aplicar nada: la migración 001 necesita
-- poder registrarse, así que esta tabla no puede venir de una migración.
CREATE TABLE schema_migrations (
  version      VARCHAR(120) NOT NULL,
  checksum     CHAR(64)     NOT NULL,   -- sha256 del fichero al aplicarse
  statements   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
  applied_at   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (version)
) ENGINE=InnoDB;
```

El `checksum` detecta el error clásico: **editar una migración que ya se aplicó**. Funciona
en la máquina de quien la editó y falla en producción, donde nunca se reaplicó. `bin/migrate`
avisa de la divergencia y **no** reaplica: la reparación correcta es escribir otra migración.

**No hay rollback y es deliberado.** En MySQL y MariaDB el DDL provoca commit implícito, así
que envolver una migración en una transacción da una falsa sensación de seguridad. El
migrador aplica sentencia a sentencia y, si una falla, corta ahí diciendo exactamente cuál.
La migración fallida no queda registrada, así que la siguiente ejecución la reintenta.

Reclamo de trabajos sin Redis (seguro bajo concurrencia con InnoDB):

```sql
SELECT id FROM jobs
 WHERE state='pending' AND run_after <= NOW(3)
 ORDER BY priority, id
 LIMIT 1
 FOR UPDATE SKIP LOCKED;
```

---

## 7. Notas de escalabilidad

- `measurements` es la única tabla que crecerá de verdad. Estimación: ~40 mediciones por
  entrada, 3 entradas/día → ~44k filas/usuario/año. **1.000 usuarios × 5 años ≈ 220M filas.**
  Manejable en InnoDB con los índices propuestos; si llega el caso, partición por `RANGE`
  sobre `YEAR(occurred_date)`.
- `daily_metrics` es el que sirve las gráficas: ~40 variables × 365 días = 14,6k filas/año
  por usuario. Las gráficas nunca tocan `measurements`.
- `extraction_runs.output_json` es lo que más ocupa. Política: archivar a disco los runs
  con más de 12 meses y dejar solo el metadato.
- Los índices llevan `user_id` primero por aislamiento y por localidad de datos: las
  consultas de un usuario tocan páginas contiguas.
