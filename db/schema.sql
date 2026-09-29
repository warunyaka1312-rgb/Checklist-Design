-- =====================================================================
-- Die Design Checklist Approval System
-- Database schema
-- Target: MySQL 5.7+ / MariaDB 10.3+ (InnoDB, utf8mb4)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS checklist_design
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE checklist_design;

-- ---------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(150) NOT NULL,
    role          ENUM('admin', 'engineer', 'manager') NOT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- customers (master data)
-- ---------------------------------------------------------------------
CREATE TABLE customers (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_code  VARCHAR(30) NULL,
    name       VARCHAR(150) NOT NULL,
    tax_id     VARCHAR(20) NULL,
    address    VARCHAR(255) NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    synced_at  DATETIME NULL,
    UNIQUE KEY uq_customers_name (name),
    UNIQUE KEY uq_customers_card_code (card_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- die_models (master data)
-- ---------------------------------------------------------------------
CREATE TABLE die_models (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_die_models_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- materials (master data) — alloy list used by checklists.material_id
-- ---------------------------------------------------------------------
CREATE TABLE materials (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(50) NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_materials_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- tempers (master data) — alloy temper used by checklists.temper_id.
-- A table rather than an ENUM so new tempers can be added without a
-- schema change; SAP already returns O, T4, T64 and H112 besides T1/T5/T6.
-- ---------------------------------------------------------------------
CREATE TABLE tempers (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(20) NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tempers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- approvers (master data) — which users may be picked as the approving
-- manager on a checklist. Curates that dropdown only: approving still
-- requires role = manager, and rows stay keyed to users so login and
-- notifications keep working.
-- ---------------------------------------------------------------------
CREATE TABLE approvers (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_approvers_user (user_id),
    CONSTRAINT fk_approvers_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- checklist_items
-- หัวข้อตรวจมาสเตอร์ เป็น flat list แยกตามชนิดแม่พิมพ์ (solid / hollow)
-- ไม่มีการจัดกลุ่มย่อย บริหารจัดการโดย admin
-- ---------------------------------------------------------------------
CREATE TABLE checklist_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    die_type    ENUM('solid', 'hollow') NOT NULL,
    topic       VARCHAR(255) NOT NULL,
    note        TEXT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_items_die_type (die_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- checklists
-- เอกสารหลัก 1 ใบ = การตรวจแบบแม่พิมพ์ 1 ครั้งของ Die-No หนึ่งตัว
-- ---------------------------------------------------------------------
CREATE TABLE checklists (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    die_no              VARCHAR(50) NOT NULL,
    tech                VARCHAR(100) NULL,
    model_id            INT UNSIGNED NOT NULL,
    customer_id         INT UNSIGNED NOT NULL,
    die_type            ENUM('solid', 'hollow') NOT NULL,
    material_id         INT UNSIGNED NOT NULL,
    temper_id           INT UNSIGNED NULL,
    created_by          INT UNSIGNED NOT NULL,
    assigned_manager_id INT UNSIGNED NULL,
    status              ENUM('draft', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'draft',
    design_pdf_path     VARCHAR(255) NULL,
    design_image_path   VARCHAR(255) NULL,
    revision_note       TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    decided_at          DATETIME NULL,
    decided_by          INT UNSIGNED NULL,
    KEY idx_checklists_die_no (die_no),
    KEY idx_checklists_status (status),
    KEY idx_checklists_model (model_id),
    KEY idx_checklists_customer (customer_id),
    KEY idx_checklists_material (material_id),
    KEY idx_checklists_temper (temper_id),
    KEY idx_checklists_created_by (created_by),
    KEY idx_checklists_assigned_manager (assigned_manager_id),
    KEY idx_checklists_decided_by (decided_by),
    CONSTRAINT fk_checklists_model FOREIGN KEY (model_id)
        REFERENCES die_models (id) ON DELETE RESTRICT,
    CONSTRAINT fk_checklists_customer FOREIGN KEY (customer_id)
        REFERENCES customers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_checklists_material FOREIGN KEY (material_id)
        REFERENCES materials (id) ON DELETE RESTRICT,
    CONSTRAINT fk_checklists_temper FOREIGN KEY (temper_id)
        REFERENCES tempers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_checklists_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_checklists_assigned_manager FOREIGN KEY (assigned_manager_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_checklists_decided_by FOREIGN KEY (decided_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- checklist_selected_items
-- หัวข้อที่ engineer เลือกมาตรวจจริงสำหรับ checklist ใบนี้ (ไม่จำเป็นต้องครบทุกหัวข้อ)
-- ---------------------------------------------------------------------
CREATE TABLE checklist_selected_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    checklist_id INT UNSIGNED NOT NULL,
    item_id      INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_checklist_item (checklist_id, item_id),
    KEY idx_selected_item (item_id),
    CONSTRAINT fk_selected_checklist FOREIGN KEY (checklist_id)
        REFERENCES checklists (id) ON DELETE CASCADE,
    CONSTRAINT fk_selected_item FOREIGN KEY (item_id)
        REFERENCES checklist_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- checklist_results
-- ผลตรวจจริงต่อหัวข้อที่ถูกเลือก (pass/fail) พร้อมรูปประกอบ
-- ---------------------------------------------------------------------
-- item_id is NULL for a checklist-specific custom item (is_custom_item = 1);
-- its topic/note then live in custom_topic/custom_note instead of the master table.
CREATE TABLE checklist_results (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    checklist_id   INT UNSIGNED NOT NULL,
    item_id        INT UNSIGNED NULL,
    is_custom_item TINYINT(1) NOT NULL DEFAULT 0,
    result         ENUM('pass', 'fail') NULL,
    comment        TEXT NULL,
    prev_result    ENUM('pass', 'fail') NULL,
    note_before    TEXT NULL,
    image_path     VARCHAR(255) NULL,
    image_source   ENUM('manual', 'api') NOT NULL DEFAULT 'manual',
    custom_topic   VARCHAR(255) NULL,
    custom_note    TEXT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_result_checklist_item (checklist_id, item_id),
    KEY idx_results_item (item_id),
    CONSTRAINT fk_results_checklist FOREIGN KEY (checklist_id)
        REFERENCES checklists (id) ON DELETE CASCADE,
    CONSTRAINT fk_results_item FOREIGN KEY (item_id)
        REFERENCES checklist_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- checklist_history
-- Audit trail: ทุก action ที่เกิดกับ checklist ใบหนึ่ง
-- ---------------------------------------------------------------------
CREATE TABLE checklist_history (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    checklist_id INT UNSIGNED NOT NULL,
    action       ENUM('submitted', 'approved', 'rejected', 'edited') NOT NULL,
    action_by    INT UNSIGNED NOT NULL,
    note         TEXT NULL,
    old_status   ENUM('draft', 'pending', 'approved', 'rejected') NULL,
    new_status   ENUM('draft', 'pending', 'approved', 'rejected') NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_history_checklist (checklist_id),
    KEY idx_history_action_by (action_by),
    CONSTRAINT fk_history_checklist FOREIGN KEY (checklist_id)
        REFERENCES checklists (id) ON DELETE CASCADE,
    CONSTRAINT fk_history_action_by FOREIGN KEY (action_by)
        REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- notifications
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    message    VARCHAR(255) NOT NULL,
    link       VARCHAR(255) NULL,
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_user (user_id),
    KEY idx_notifications_is_read (is_read),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- api_settings
-- Config สำหรับปุ่มดึงรูปจาก API ภายนอก (ยังไม่ implement การเรียกจริงใน phase นี้)
-- ---------------------------------------------------------------------
CREATE TABLE api_settings (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    endpoint_url VARCHAR(255) NOT NULL,
    api_key     VARCHAR(255) NULL,
    is_enabled  TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
