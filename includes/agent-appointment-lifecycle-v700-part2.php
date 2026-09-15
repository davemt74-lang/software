<?php
declare(strict_types=1);

function agent_appointment_lifecycle_ensure_schema_v700(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!agent_scheduling_schema_ready_v430($pdo))agent_scheduling_ensure_schema_v430($pdo);

    if(!column_exists('agent_scheduling_bookings','lifecycle_status')){
        $pdo->exec("ALTER TABLE agent_scheduling_bookings ADD COLUMN lifecycle_status VARCHAR(24) NOT NULL DEFAULT 'confirmed' AFTER status");
    }
    if(!column_exists('agent_scheduling_bookings','rescheduled_at')){
        $pdo->exec("ALTER TABLE agent_scheduling_bookings ADD COLUMN rescheduled_at DATETIME NULL AFTER cancelled_at");
    }
    if(!column_exists('agent_scheduling_bookings','no_show_at')){
        $pdo->exec("ALTER TABLE agent_scheduling_bookings ADD COLUMN no_show_at DATETIME NULL AFTER completed_at");
    }
    if(!column_exists('agent_scheduling_bookings','last_lifecycle_event_at')){
        $pdo->exec("ALTER TABLE agent_scheduling_bookings ADD COLUMN last_lifecycle_event_at DATETIME NULL AFTER no_show_at");
    }
    if(!column_exists('agent_scheduling_event_types','confirmation_enabled')){
        $pdo->exec("ALTER TABLE agent_scheduling_event_types ADD COLUMN confirmation_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER max_bookings_per_day");
    }
    if(!column_exists('agent_scheduling_event_types','reminder_24h_enabled')){
        $pdo->exec("ALTER TABLE agent_scheduling_event_types ADD COLUMN reminder_24h_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER confirmation_enabled");
    }
    if(!column_exists('agent_scheduling_event_types','reminder_soon_minutes')){
        $pdo->exec("ALTER TABLE agent_scheduling_event_types ADD COLUMN reminder_soon_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER reminder_24h_enabled");
    }
    if(!column_exists('agent_scheduling_event_types','agent_prep_minutes')){
        $pdo->exec("ALTER TABLE agent_scheduling_event_types ADD COLUMN agent_prep_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60 AFTER reminder_soon_minutes");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_scheduling_intake_questions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      event_type_id BIGINT UNSIGNED NOT NULL,
      question_key VARCHAR(80) NOT NULL,
      label VARCHAR(500) NOT NULL,
      question_type VARCHAR(24) NOT NULL DEFAULT 'short_text',
      options_json LONGTEXT NULL,
      is_required TINYINT(1) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_intake_key (event_type_id,question_key),
      INDEX idx_agent_intake_event (event_type_id,is_active,sort_order,id),
      CONSTRAINT fk_agent_intake_event FOREIGN KEY (event_type_id) REFERENCES agent_scheduling_event_types(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_scheduling_intake_answers (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      booking_id BIGINT UNSIGNED NOT NULL,
      question_id BIGINT UNSIGNED NOT NULL,
      question_key VARCHAR(80) NOT NULL,
      question_label VARCHAR(500) NOT NULL,
      answer_text TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_intake_answer (booking_id,question_id),
      INDEX idx_agent_intake_answer_booking (booking_id,id),
      CONSTRAINT fk_agent_intake_answer_booking FOREIGN KEY (booking_id) REFERENCES agent_scheduling_bookings(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_intake_answer_question FOREIGN KEY (question_id) REFERENCES agent_scheduling_intake_questions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_scheduling_lifecycle_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      booking_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(50) NOT NULL,
      from_status VARCHAR(24) NOT NULL DEFAULT '',
      to_status VARCHAR(24) NOT NULL DEFAULT '',
      actor_type VARCHAR(24) NOT NULL DEFAULT 'system',
      actor_user_id INT UNSIGNED NULL,
      actor_agent_id BIGINT UNSIGNED NULL,
      details_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_agent_lifecycle_booking (booking_id,created_at,id),
      INDEX idx_agent_lifecycle_owner (owner_user_id,created_at,id),
      INDEX idx_agent_lifecycle_type (event_type,created_at,id),
      CONSTRAINT fk_agent_lifecycle_booking FOREIGN KEY (booking_id) REFERENCES agent_scheduling_bookings(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_lifecycle_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_lifecycle_actor_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_agent_lifecycle_actor_agent FOREIGN KEY (actor_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_scheduling_automation_deliveries (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      booking_id BIGINT UNSIGNED NOT NULL,
      automation_key VARCHAR(50) NOT NULL,
      recipient_key VARCHAR(255) NOT NULL,
      occurrence_key CHAR(64) NOT NULL,
      recipient_user_id INT UNSIGNED NULL,
      recipient_email VARCHAR(190) NOT NULL DEFAULT '',
      channel VARCHAR(24) NOT NULL,
      due_at DATETIME NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'pending',
      attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      last_error VARCHAR(1000) NOT NULL DEFAULT '',
      sent_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_automation_delivery (booking_id,automation_key,recipient_key,occurrence_key),
      INDEX idx_agent_automation_due (status,due_at,id),
      INDEX idx_agent_automation_user (recipient_user_id,status,due_at,id),
      CONSTRAINT fk_agent_automation_booking FOREIGN KEY (booking_id) REFERENCES agent_scheduling_bookings(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_automation_user FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if(!column_exists('agent_scheduling_automation_deliveries','occurrence_key')){
        $pdo->exec("ALTER TABLE agent_scheduling_automation_deliveries ADD COLUMN occurrence_key CHAR(64) NOT NULL DEFAULT '' AFTER recipient_key");
        try{$pdo->exec("ALTER TABLE agent_scheduling_automation_deliveries DROP INDEX uq_agent_automation_delivery, ADD UNIQUE KEY uq_agent_automation_delivery (booking_id,automation_key,recipient_key,occurrence_key)");}catch(Throwable $ignored){}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_scheduling_agent_briefs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      booking_id BIGINT UNSIGNED NOT NULL,
      agent_id BIGINT UNSIGNED NULL,
      brief_text MEDIUMTEXT NOT NULL,
      context_json LONGTEXT NULL,
      prepared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_brief_booking (booking_id),
      INDEX idx_agent_brief_agent (agent_id,prepared_at,id),
      CONSTRAINT fk_agent_brief_booking FOREIGN KEY (booking_id) REFERENCES agent_scheduling_bookings(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_brief_agent FOREIGN KEY (agent_id) REFERENCES user_agents(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_scheduling_followups (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      booking_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      created_by_user_id INT UNSIGNED NULL,
      created_by_agent_id BIGINT UNSIGNED NULL,
      notes TEXT NULL,
      task_title VARCHAR(190) NOT NULL DEFAULT '',
      task_due_at DATETIME NULL,
      draft_subject VARCHAR(190) NOT NULL DEFAULT '',
      draft_body MEDIUMTEXT NULL,
      message_status VARCHAR(24) NOT NULL DEFAULT 'draft',
      sent_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_agent_followup_booking (booking_id,created_at,id),
      INDEX idx_agent_followup_owner (owner_user_id,message_status,task_due_at,id),
      CONSTRAINT fk_agent_followup_booking FOREIGN KEY (booking_id) REFERENCES agent_scheduling_bookings(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_followup_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_followup_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_agent_followup_agent FOREIGN KEY (created_by_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Preserve legacy truth while giving Phase 7 a richer state vocabulary.
    $pdo->exec("UPDATE agent_scheduling_bookings
               SET lifecycle_status=CASE
                 WHEN status='cancelled' THEN 'cancelled'
                 WHEN status='completed' THEN 'completed'
                 WHEN status='no_show' THEN 'no_show'
                 ELSE COALESCE(NULLIF(lifecycle_status,''),'confirmed')
               END");
}

