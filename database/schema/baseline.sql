
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `acc_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_account` int(11) NOT NULL,
  `can_reveal` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'may reveal Gateway password, auth codes, UTR, NI',
  `is_accountant` tinyint(4) NOT NULL DEFAULT 1 COMMENT 'appears in the Assigned to lists',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acc_access` (`fk_id_ent`,`fk_id_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `acc_company_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_company_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_company` int(11) NOT NULL COMMENT 'cat_company_house.id',
  `f_tracked` tinyint(4) NOT NULL DEFAULT 1,
  `year_end_day` tinyint(4) DEFAULT NULL,
  `year_end_month` tinyint(4) DEFAULT NULL,
  `first_period_end` date DEFAULT NULL COMMENT 'override for the first accounts period end',
  `track_from` date DEFAULT NULL COMMENT 'ignore deadlines before this date',
  `cs_review_date` date DEFAULT NULL COMMENT 'override; else cat_company_house.confirmation_statement_date',
  `vat_registered` tinyint(4) NOT NULL DEFAULT 0,
  `vat_frequency` enum('Q','M','A') NOT NULL DEFAULT 'Q',
  `vat_stagger` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=Mar/Jun/Sep/Dec 2=Apr/Jul/Oct/Jan 3=May/Aug/Nov/Feb',
  `vat_scheme` varchar(40) DEFAULT NULL,
  `vat_reg_date` date DEFAULT NULL,
  `paye_registered` tinyint(4) NOT NULL DEFAULT 0,
  `paye_ref` varchar(30) DEFAULT NULL,
  `accounts_office_ref` varchar(30) DEFAULT NULL,
  `pay_frequency` enum('M','W','4W') NOT NULL DEFAULT 'M',
  `payday_day` tinyint(4) NOT NULL DEFAULT 28 COMMENT 'monthly payroll: day of month',
  `first_payday` date DEFAULT NULL COMMENT 'weekly / 4-weekly payroll anchor',
  `paye_quarterly` tinyint(4) NOT NULL DEFAULT 0,
  `eps_required` tinyint(4) NOT NULL DEFAULT 0,
  `paye_start_date` date DEFAULT NULL,
  `fk_accountant` int(11) NOT NULL DEFAULT 0 COMMENT 't_account.id',
  `notes` text DEFAULT NULL,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  `fk_compliance_owner` int(11) DEFAULT NULL,
  `cs_date_is` varchar(6) NOT NULL DEFAULT 'REVIEW',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acc_company` (`fk_id_ent`,`fk_id_company`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `acc_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `entity_type` enum('COMPANY','PERSON') NOT NULL,
  `entity_id` int(11) NOT NULL,
  `fk_id_obligation` int(11) NOT NULL DEFAULT 0,
  `doc_title` varchar(150) DEFAULT NULL,
  `stored_name` varchar(120) NOT NULL,
  `orig_name` varchar(200) NOT NULL,
  `mime` varchar(100) NOT NULL,
  `size_bytes` int(11) NOT NULL DEFAULT 0,
  `f_deleted` tinyint(4) NOT NULL DEFAULT 0,
  `uploaded_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_acc_doc_entity` (`fk_id_ent`,`entity_type`,`entity_id`,`f_deleted`,`id`),
  KEY `idx_acc_doc_ob` (`fk_id_obligation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `acc_obligation_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_obligation_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_obligation` int(11) NOT NULL,
  `action` varchar(40) NOT NULL,
  `details` text DEFAULT NULL,
  `fk_id_account` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_acc_log_ob` (`fk_id_obligation`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `acc_obligation_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_obligation_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `type_code` varchar(20) NOT NULL COMMENT 'fixed code used by the deadline engine',
  `type_name` varchar(120) NOT NULL,
  `area` enum('VAT','CT','CS','ACCOUNTS','PAYROLL','PERSONAL','OTHER') NOT NULL,
  `authority` enum('HMRC','CH','OTHER') NOT NULL DEFAULT 'HMRC',
  `completion` enum('FILE','PAY','FILE_PAY') NOT NULL DEFAULT 'FILE' COMMENT 'what makes it done',
  `reminder_days` varchar(60) NOT NULL DEFAULT '30,14,7,1',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acc_type` (`fk_id_ent`,`type_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `acc_obligations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_obligations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `entity_type` enum('COMPANY','PERSON') NOT NULL,
  `entity_id` int(11) NOT NULL COMMENT 'cat_company_house.id or t_account.id',
  `fk_type` int(11) NOT NULL,
  `type_code` varchar(20) NOT NULL,
  `area` varchar(12) NOT NULL,
  `completion` enum('FILE','PAY','FILE_PAY') NOT NULL DEFAULT 'FILE',
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `due_date` date NOT NULL,
  `status` enum('PENDING','IN_PROGRESS','FILED','PAID','NOT_REQUIRED') NOT NULL DEFAULT 'PENDING',
  `f_done` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'kept in step with status for fast counts',
  `f_auto` tinyint(4) NOT NULL DEFAULT 1,
  `gen_key` varchar(100) DEFAULT NULL COMMENT 'set on auto rows, stops duplicates',
  `title` varchar(150) DEFAULT NULL COMMENT 'manual items only',
  `fk_assigned` int(11) NOT NULL DEFAULT 0,
  `filed_date` date DEFAULT NULL,
  `filed_by` int(11) NOT NULL DEFAULT 0,
  `submission_ref` varchar(100) DEFAULT NULL,
  `amount_due` decimal(14,2) DEFAULT NULL,
  `amount_paid` decimal(14,2) DEFAULT NULL,
  `paid_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acc_gen` (`fk_id_ent`,`gen_key`),
  KEY `idx_acc_due` (`fk_id_ent`,`f_done`,`due_date`),
  KEY `idx_acc_entity` (`fk_id_ent`,`entity_type`,`entity_id`,`area`,`due_date`),
  KEY `idx_acc_assigned` (`fk_id_ent`,`fk_assigned`,`f_done`,`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `acc_person_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_person_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_account` int(11) NOT NULL COMMENT 't_account.id (director)',
  `sa_registered` tinyint(4) NOT NULL DEFAULT 0,
  `poa_required` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'payments on account',
  `first_tax_year` smallint(6) DEFAULT NULL COMMENT '2024 = tax year 2024-25',
  `utr_enc` text DEFAULT NULL,
  `utr_last4` varchar(4) DEFAULT NULL,
  `ni_enc` text DEFAULT NULL,
  `ni_last4` varchar(4) DEFAULT NULL,
  `fk_accountant` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acc_person` (`fk_id_ent`,`fk_id_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `acc_reminder_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_reminder_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_obligation` int(11) NOT NULL,
  `remind_key` varchar(20) NOT NULL COMMENT 'D30, D7, OD2026-10-24 ...',
  `sent_to` varchar(200) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acc_rem` (`fk_id_obligation`,`remind_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `acc_reveal_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_reveal_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `entity_type` varchar(10) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `field_name` varchar(40) NOT NULL,
  `result` varchar(10) NOT NULL DEFAULT 'SHOWN' COMMENT 'SHOWN or DENIED',
  `fk_id_account` int(11) NOT NULL DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_acc_reveal` (`fk_id_ent`,`entity_type`,`entity_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `acc_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `setting_key` varchar(60) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acc_settings` (`fk_id_ent`,`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `api_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_connections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_branch` int(11) NOT NULL,
  `provider` varchar(40) NOT NULL,
  `status` enum('NEW','CONNECTED','EXPIRED','ERROR','OFF') NOT NULL DEFAULT 'NEW',
  `external_id` varchar(80) DEFAULT NULL,
  `token_ref` varchar(120) DEFAULT NULL,
  `scopes` varchar(255) DEFAULT NULL,
  `connected_by` int(11) DEFAULT NULL,
  `connected_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `fk_id_mp_profile` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shop_provider` (`fk_id_branch`,`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `api_sync_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_sync_runs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_connection` int(11) NOT NULL,
  `kind` enum('ORDERS','INVENTORY','PRICES','SETTLEMENT','ADS','RETURNS','LISTINGS') NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `rows_in` int(11) NOT NULL DEFAULT 0,
  `rows_new` int(11) NOT NULL DEFAULT 0,
  `rows_changed` int(11) NOT NULL DEFAULT 0,
  `fk_id_imp_batch` int(11) DEFAULT NULL,
  `status` enum('RUNNING','OK','PARTIAL','FAILED') NOT NULL DEFAULT 'RUNNING',
  `message` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `k_conn` (`fk_id_connection`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ars_iso_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ars_iso_values` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `iso_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `iso_value` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_no` int(11) NOT NULL DEFAULT 0,
  `f_active` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `aud_check_waivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aud_check_waivers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_run` int(11) NOT NULL,
  `check_code` varchar(40) NOT NULL,
  `reason` text NOT NULL,
  `waived_by` int(11) NOT NULL,
  `waived_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_waiver` (`fk_id_run`,`check_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Owner waivers per month close';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `aud_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aud_checks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_run` int(11) NOT NULL,
  `check_code` varchar(40) NOT NULL,
  `status` enum('PENDING','GREEN','RED','WAIVED','NA') NOT NULL DEFAULT 'PENDING',
  `detail` varchar(500) DEFAULT NULL,
  `open_items` int(11) NOT NULL DEFAULT 0,
  `fix_url` varchar(255) DEFAULT NULL,
  `fk_id_task` int(11) DEFAULT NULL,
  `waived_by` int(11) DEFAULT NULL,
  `waive_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chk` (`fk_id_run`,`check_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `aud_expense_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aud_expense_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_category` int(11) NOT NULL,
  `method` enum('BY_SALES','BY_ORDERS','EQUAL','FIXED_PCT','DIRECT') NOT NULL,
  `json_pct` text DEFAULT NULL,
  `d_effective_from` date NOT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `aud_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aud_lines` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_run` int(11) NOT NULL,
  `bucket` varchar(40) NOT NULL,
  `source_table` varchar(64) NOT NULL,
  `source_id` bigint(20) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `source_version` smallint(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_run` (`fk_id_run`,`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `aud_positions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aud_positions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_run` int(11) NOT NULL,
  `fk_id_party` int(11) NOT NULL,
  `cash_due` decimal(14,2) NOT NULL,
  `stock_at_cost` decimal(14,2) NOT NULL,
  `marketplace_held` decimal(14,2) NOT NULL,
  `owed` decimal(14,2) NOT NULL,
  `net_position` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_run` (`fk_id_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `aud_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aud_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_code` varchar(30) NOT NULL,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `period_month` char(7) NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `status` enum('OPEN','CHECKED','LOCKED','SUPERSEDED') NOT NULL DEFAULT 'OPEN',
  `score` tinyint(4) DEFAULT NULL,
  `gross_sales` decimal(14,2) DEFAULT NULL,
  `refunds` decimal(14,2) DEFAULT NULL,
  `fees` decimal(14,2) DEFAULT NULL,
  `ads` decimal(14,2) DEFAULT NULL,
  `postage` decimal(14,2) DEFAULT NULL,
  `cost_of_goods` decimal(14,2) DEFAULT NULL,
  `direct_expenses` decimal(14,2) DEFAULT NULL,
  `shared_expenses` decimal(14,2) DEFAULT NULL,
  `net_profit` decimal(14,2) DEFAULT NULL,
  `loss_brought_forward` decimal(14,2) DEFAULT NULL,
  `legacy_table` varchar(40) DEFAULT NULL,
  `legacy_id` int(11) DEFAULT NULL,
  `locked_by` int(11) DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `fk_id_previous_version` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `profit_route` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_code` (`run_code`),
  UNIQUE KEY `uq_run` (`fk_id_branch`,`period_month`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `aud_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aud_shares` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_run` int(11) NOT NULL,
  `fk_id_party` int(11) NOT NULL,
  `role` enum('VA','HANDLER','SUPPLIER','INVESTOR','PARTNER','OWNER') NOT NULL,
  `basis` varchar(120) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `carried_forward` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `ix_run` (`fk_id_run`),
  KEY `ix_party` (`fk_id_party`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `aud_stock_owner`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aud_stock_owner` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_run` int(11) NOT NULL,
  `fk_id_party` int(11) NOT NULL,
  `fk_id_product` int(11) NOT NULL,
  `opening_qty` int(11) NOT NULL,
  `in_qty` int(11) NOT NULL,
  `sold_qty` int(11) NOT NULL,
  `returned_qty` int(11) NOT NULL,
  `damaged_qty` int(11) NOT NULL,
  `transfer_qty` int(11) NOT NULL,
  `closing_qty` int(11) NOT NULL,
  `closing_value_gbp` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_run` (`fk_id_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `bank_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_bank_id` int(11) NOT NULL,
  `question` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `bank_statement_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_statement_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `fk_id_bank` int(11) NOT NULL,
  `file_format` varchar(10) NOT NULL,
  `header_row` smallint(6) NOT NULL DEFAULT 1,
  `date_column` varchar(60) NOT NULL,
  `date_format` varchar(20) NOT NULL,
  `amount_rule` varchar(20) NOT NULL,
  `amount_column` varchar(60) DEFAULT NULL,
  `debit_column` varchar(60) DEFAULT NULL,
  `credit_column` varchar(60) DEFAULT NULL,
  `description_column` varchar(60) NOT NULL,
  `reference_column` varchar(60) DEFAULT NULL,
  `counterparty_column` varchar(60) DEFAULT NULL,
  `txn_id_column` varchar(60) DEFAULT NULL,
  `currency_column` varchar(60) DEFAULT NULL,
  `balance_column` varchar(60) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bsp_code` (`fk_id_ent`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='How to read one bank / provider statement file (IC-0)';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `bank_statement_profiles` VALUES
(1,0,'EX_BANK_SIGNED','Bank statement – one signed amount column (example)',0,'CSV',1,'Date','d/m/Y','ONE_SIGNED','Amount',NULL,NULL,'Description','Reference',NULL,'Transaction ID',NULL,'Balance',1),
(2,0,'EX_BANK_DEBIT_CREDIT','Bank statement – money out / money in columns (example)',0,'CSV',1,'Date','Y-m-d','TWO_COLUMNS',NULL,'Money out','Money in','Memo',NULL,'Counterparty',NULL,NULL,'Balance',1);
DROP TABLE IF EXISTS `bulk_job_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bulk_job_rows` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_job` int(11) NOT NULL,
  `table_name` varchar(64) NOT NULL,
  `row_id` int(11) NOT NULL,
  `old_json` text NOT NULL,
  `new_json` text NOT NULL,
  `status` enum('OK','SKIPPED','ERROR') NOT NULL DEFAULT 'OK',
  `message` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `k_job` (`fk_id_job`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `bulk_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bulk_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `job_no` varchar(30) NOT NULL,
  `list_key` varchar(60) NOT NULL,
  `fk_id_account` int(11) NOT NULL,
  `change_json` text NOT NULL,
  `row_count` int(11) NOT NULL,
  `status` enum('PREVIEW','APPLIED','UNDONE','FAILED') NOT NULL DEFAULT 'PREVIEW',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `applied_at` datetime DEFAULT NULL,
  `undone_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_job` (`job_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cal_non_working_days`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cal_non_working_days` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_country` int(11) NOT NULL,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `d_day` date NOT NULL,
  `name` varchar(120) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cal` (`fk_id_country`,`fk_id_ent`,`d_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Bank holidays and closing days per country (R-H-24)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `car_preset_group_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `car_preset_group_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_group` int(11) NOT NULL,
  `fk_id_pkg_svc` int(11) NOT NULL COMMENT 't_carrier_pkg_svc.id',
  `f_pinned` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'star inside the group: shown first in this group',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_car_preset_item` (`fk_id_group`,`fk_id_pkg_svc`),
  KEY `idx_car_preset_item_preset` (`fk_id_pkg_svc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `car_preset_group_scope`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `car_preset_group_scope` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_group` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL DEFAULT 0 COMMENT 'cat_marketplaces.id',
  `fk_id_branch` int(11) NOT NULL DEFAULT 0 COMMENT 't_branches.id (shop)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_car_preset_scope` (`fk_id_group`,`fk_id_marketplace`,`fk_id_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `car_preset_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `car_preset_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `group_name` varchar(100) NOT NULL,
  `group_code` varchar(20) NOT NULL DEFAULT '' COMMENT 'short code, e.g. RM-UNTR (optional)',
  `color` varchar(7) NOT NULL DEFAULT '#17a2b8',
  `description` varchar(255) NOT NULL DEFAULT '',
  `fk_id_default_preset` int(11) NOT NULL DEFAULT 0 COMMENT 't_carrier_pkg_svc.id, must be in the group, 0 = none',
  `scope_mode` enum('ALL','LIMITED') NOT NULL DEFAULT 'ALL' COMMENT 'ALL = every marketplace / shop, LIMITED = only car_preset_group_scope rows',
  `f_favourite` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'star: shown first in lists and dropdowns',
  `f_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `legacy_id` int(11) NOT NULL DEFAULT 0 COMMENT 't_carrier_preset_groups.id when copied from Carrier Shipping',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_car_preset_groups_list` (`f_active`,`f_favourite`,`sort_order`),
  KEY `idx_car_preset_groups_legacy` (`legacy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `car_preset_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `car_preset_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_group` int(11) NOT NULL DEFAULT 0,
  `action` varchar(30) NOT NULL,
  `detail` varchar(500) NOT NULL DEFAULT '',
  `user_id` int(11) NOT NULL DEFAULT 0,
  `user_name` varchar(150) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_car_preset_log_group` (`fk_id_group`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `card_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `card_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `card_category_name` varchar(150) NOT NULL,
  `f_active` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `card_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `card_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `card_type` varchar(150) NOT NULL,
  `f_active` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `carrier_service_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `carrier_service_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_mp_profile` int(11) NOT NULL,
  `service_text` varchar(150) NOT NULL,
  `fk_id_carrier` int(11) NOT NULL,
  `fk_id_car_pkg_svc` int(11) NOT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_csm` (`fk_id_mp_profile`,`service_text`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Maps a marketplace service text to our carrier service (IC-13)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `carrier_service_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `carrier_service_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `priority` smallint(6) NOT NULL DEFAULT 100,
  `fk_id_mp_profile` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `dest_country_group` varchar(40) DEFAULT NULL,
  `weight_from_g` int(11) DEFAULT NULL,
  `weight_to_g` int(11) DEFAULT NULL,
  `max_length_cm` decimal(8,2) DEFAULT NULL,
  `max_girth_cm` decimal(8,2) DEFAULT NULL,
  `value_from` decimal(14,2) DEFAULT NULL,
  `value_to` decimal(14,2) DEFAULT NULL,
  `service_level` varchar(20) DEFAULT NULL,
  `fk_id_carrier` int(11) NOT NULL,
  `fk_id_car_pkg_svc` int(11) NOT NULL,
  `fk_id_label_template` int(11) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `ix_csr` (`fk_id_ent`,`f_active`,`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Carrier service selection rules (R-H-30)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_account_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_account_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `account_type_name` varchar(500) NOT NULL,
  `iso_account_type` varchar(10) NOT NULL,
  `f_active` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`account_type_name`),
  UNIQUE KEY `fk_id_ent_2` (`fk_id_ent`,`account_type_name`),
  UNIQUE KEY `fk_id_ent_3` (`fk_id_ent`,`iso_account_type`),
  UNIQUE KEY `uniq_ent_acctypename` (`fk_id_ent`,`account_type_name`),
  UNIQUE KEY `uniq_ent_isoaccounttype` (`fk_id_ent`,`iso_account_type`),
  KEY `idx_ent_acc_types` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_account_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_account_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `account_type_name` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `iso_account_type` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_active` int(11) NOT NULL,
  `role_code` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ent_acctypename` (`fk_id_ent`,`account_type_name`),
  UNIQUE KEY `uniq_ent_isoaccounttype` (`fk_id_ent`,`iso_account_type`),
  KEY `idx_ent_acc_types` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_adjustment_reasons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_adjustment_reasons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reason_name` varchar(150) NOT NULL,
  `f_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_active` (`f_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_banks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_banks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `f_active` tinyint(4) NOT NULL,
  `bank_short_name` varchar(50) NOT NULL,
  `bank_name` varchar(150) NOT NULL,
  `bank_iso_code` varchar(80) NOT NULL,
  `country_id` int(11) NOT NULL,
  `bank_email` varchar(150) NOT NULL,
  `bank_telephone1` varchar(80) NOT NULL,
  `bank_telephone2` varchar(80) NOT NULL,
  `bank_telephone3` varchar(80) NOT NULL,
  `bank_address` varchar(255) NOT NULL,
  `bank_email_2` varchar(150) NOT NULL,
  `bank_email_3` varchar(150) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_brand_registrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_brand_registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `fk_id_brand` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL,
  `reg_status` varchar(12) NOT NULL DEFAULT 'NONE',
  `reg_ref` varchar(120) DEFAULT NULL,
  `d_registered` date DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_cbr` (`fk_id_brand`,`fk_id_marketplace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Brand registration / protection status per marketplace (R-D-99)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_brands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_brands` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `fk_id_company_house` int(11) DEFAULT NULL,
  `brand_name` varchar(150) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `website` varchar(300) DEFAULT NULL,
  `tm_status` varchar(20) DEFAULT NULL,
  `tm_number` varchar(40) DEFAULT NULL,
  `tm_office` varchar(40) DEFAULT NULL,
  `tm_classes` varchar(100) DEFAULT NULL,
  `tm_filed_date` date DEFAULT NULL,
  `tm_registered_date` date DEFAULT NULL,
  `tm_expiry_date` date DEFAULT NULL,
  `fk_id_tm_owner` int(11) DEFAULT NULL,
  `gs1_prefix` varchar(20) DEFAULT NULL,
  `asset_folder_url` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company_house` (`fk_id_company_house`),
  KEY `idx_active` (`f_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_catalog`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_catalog` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `iso_cat_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `descr` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `iso` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `iso` (`iso`),
  KEY `fk_ent_in_cat_catalog` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_company_house`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_company_house` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_director_1` int(11) DEFAULT NULL,
  `director_1_share` varchar(20) DEFAULT NULL,
  `fk_director_2` int(11) DEFAULT NULL,
  `director_2_share` varchar(20) DEFAULT NULL,
  `fk_director_3` int(11) DEFAULT NULL,
  `director_3_share` varchar(20) DEFAULT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `registration_no` varchar(100) DEFAULT NULL,
  `vat_no` varchar(100) DEFAULT NULL,
  `eori_no` varchar(100) DEFAULT NULL,
  `iso_code` varchar(100) DEFAULT NULL,
  `fk_country_id` int(11) DEFAULT NULL,
  `company_photo_submitted_id` int(11) DEFAULT NULL,
  `creation_date` date DEFAULT NULL,
  `confirmation_statement_date` date DEFAULT NULL,
  `file_return_date` date DEFAULT NULL,
  `company_login_pc_id` int(11) DEFAULT NULL,
  `company_house_telephone` varchar(100) DEFAULT NULL,
  `company_telephone` varchar(100) DEFAULT NULL,
  `company_address` varchar(255) DEFAULT NULL,
  `company_email_1` varchar(150) DEFAULT NULL,
  `company_emial_2` varchar(150) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL,
  `fk_director_1_home_addr` int(11) NOT NULL,
  `fk_director_1_reg_addr` int(11) NOT NULL,
  `fk_director_1_reg_mobile` int(11) NOT NULL,
  `fk_director_1_reg_email` int(11) NOT NULL,
  `incorporate_date` date NOT NULL DEFAULT '0000-00-00',
  `company_utr` varchar(50) NOT NULL,
  `authentication_code` varchar(50) NOT NULL,
  `gateway_id` varchar(50) NOT NULL,
  `gateway_pass` varchar(50) NOT NULL,
  `hmrc_activation_code` varchar(50) NOT NULL,
  `company_creation` varchar(50) NOT NULL,
  `company_website_link` varchar(200) NOT NULL,
  `f_external` tinyint(4) NOT NULL DEFAULT 0 COMMENT '1 = company of an External Contact',
  `sic_code` varchar(10) DEFAULT NULL,
  `f_tenant_main` tinyint(4) NOT NULL DEFAULT 0,
  `trading_address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_country`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_country` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `country_code` int(11) NOT NULL,
  `country_name` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `iso_code` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_active` int(11) NOT NULL,
  `f_order` tinyint(4) NOT NULL DEFAULT 2,
  `iso3` char(3) DEFAULT NULL,
  `fk_id_default_currency` int(11) DEFAULT NULL,
  `timezone` varchar(40) DEFAULT NULL,
  `weekend_days` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`country_name`),
  UNIQUE KEY `fk_id_ent_2` (`fk_id_ent`,`country_code`),
  UNIQUE KEY `fk_id_ent_3` (`fk_id_ent`,`iso_code`),
  UNIQUE KEY `uniq_ent_countrycode` (`fk_id_ent`,`country_code`),
  UNIQUE KEY `uniq_ent_countryname` (`fk_id_ent`,`country_name`),
  UNIQUE KEY `uniq_ent_isocode` (`fk_id_ent`,`iso_code`),
  KEY `idx_id_ent_in_country` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_currency`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_currency` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `currency_code` varchar(20) NOT NULL,
  `currency_name` varchar(255) DEFAULT NULL,
  `currency_symbol` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_currency` (`currency_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_doc_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_doc_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `doc_type_name` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `iso_doc_type` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_active` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`doc_type_name`),
  UNIQUE KEY `fk_id_ent_2` (`fk_id_ent`,`iso_doc_type`),
  KEY `idx_id_ent_in_doc_types` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_incoterm`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_incoterm` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `incoterm_code` varchar(20) NOT NULL,
  `incoterm_name` varchar(255) DEFAULT NULL,
  `incoterm_descr` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_incoterm` (`incoterm_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_inventory_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_inventory_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location_name` varchar(150) NOT NULL,
  `fk_id_location_type` int(11) NOT NULL,
  `fk_id_parent` int(11) DEFAULT NULL COMMENT 'hierarchy: Shelf 2 inside Warehouse 1',
  `f_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fk_id_ent` int(11) DEFAULT NULL,
  `location_code` varchar(30) DEFAULT NULL,
  `kind` enum('WAREHOUSE','SHELF','MARKETPLACE_FULFILMENT','THREE_PL','TRANSIT','PRODUCTION','SUPPLIER','DAMAGED','CUSTOMER','RETURNS','FBA','3PL','ZONE','BIN','UNASSIGNED','RECEIVING','DISPATCH','SHIPPED','QUARANTINE','MISSING','WRITTEN_OFF') DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `f_sellable` tinyint(4) NOT NULL DEFAULT 1,
  `legacy_warehouse_id` int(11) DEFAULT NULL,
  `fk_id_site` int(11) DEFAULT NULL,
  `f_wh_managed` tinyint(4) NOT NULL DEFAULT 0,
  `f_default_site` tinyint(4) NOT NULL DEFAULT 0,
  `shared_companies` varchar(300) DEFAULT NULL,
  `zone_type` varchar(20) DEFAULT NULL,
  `bin_type` varchar(20) DEFAULT NULL,
  `system_role` varchar(20) DEFAULT NULL,
  `level_code` varchar(10) DEFAULT NULL,
  `position_code` varchar(10) DEFAULT NULL,
  `pick_seq` int(11) DEFAULT NULL,
  `capacity_units` int(11) DEFAULT NULL,
  `f_pickable` tinyint(4) NOT NULL DEFAULT 1,
  `f_receivable` tinyint(4) NOT NULL DEFAULT 1,
  `f_prime` tinyint(4) NOT NULL DEFAULT 0,
  `barcode_value` varchar(60) DEFAULT NULL,
  `fk_id_generator_run` int(11) DEFAULT NULL,
  `deactivated_reason` varchar(255) DEFAULT NULL,
  `address_text` text DEFAULT NULL,
  `fk_id_manager` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loc_site_code` (`fk_id_site`,`location_code`),
  UNIQUE KEY `uq_loc_barcode` (`fk_id_ent`,`barcode_value`),
  KEY `idx_type` (`fk_id_location_type`),
  KEY `idx_parent` (`fk_id_parent`),
  KEY `idx_active` (`f_active`),
  KEY `ix_loc_site` (`fk_id_site`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_ip_burgers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_ip_burgers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `ip_burger_ip` varchar(150) NOT NULL,
  `isp_code` varchar(50) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_location_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_location_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(100) NOT NULL,
  `f_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_active` (`f_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_marketplace_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_marketplace_fields` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_marketplace` int(11) NOT NULL,
  `field_label` varchar(150) NOT NULL,
  `field_name` varchar(150) NOT NULL COMMENT 'auto: lowercase label, spaces -> _',
  `field_type` enum('text','textarea','file','date','number','decimal','select','checkbox','url') NOT NULL DEFAULT 'text',
  `field_options` text DEFAULT NULL COMMENT 'JSON array of options, only for select type',
  `f_required` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `f_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'soft delete: 0 = deleted',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_marketplace_field_name` (`fk_id_marketplace`,`field_name`),
  KEY `idx_marketplace_active` (`fk_id_marketplace`,`f_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_marketplaces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_marketplaces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `marketplace_short` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marketplace_descr` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `marketplace_payout` enum('Daily','Weekly','Monthly') NOT NULL DEFAULT 'Daily',
  `iso_code` varchar(50) NOT NULL,
  `country_id` int(11) NOT NULL,
  `telephone1` varchar(50) NOT NULL,
  `telephone2` varchar(50) NOT NULL,
  `telephone3` varchar(50) NOT NULL,
  `address` varchar(255) NOT NULL,
  `email1` varchar(100) NOT NULL,
  `email2` varchar(100) NOT NULL,
  `fee_value` varchar(10) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_id_ent_in_marketplaces` (`fk_id_ent`),
  KEY `idx_f_active` (`f_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_mobile_network`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_mobile_network` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_country` int(11) NOT NULL,
  `network_name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_active` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`fk_id_country`,`network_name`),
  KEY `fk_id_country_mobile_network` (`fk_id_country`),
  KEY `idx_id_ent_in_mobile_network` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_module_controllers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_module_controllers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_module` int(11) NOT NULL,
  `controller_name` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `iso_controller` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `f_active` smallint(6) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_module` (`fk_id_module`,`controller_name`),
  UNIQUE KEY `fk_id_module_2` (`fk_id_module`,`iso_controller`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_module_entities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_module_entities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_module` int(11) NOT NULL,
  `entity_name` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `iso_entity` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_active` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_module` (`fk_id_module`,`entity_name`),
  UNIQUE KEY `fk_id_module_2` (`fk_id_module`,`iso_entity`),
  KEY `fk_id_module_entities` (`fk_id_module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_module_params`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_module_params` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_module` int(11) NOT NULL,
  `param_name` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `l_ent_branch` enum('E','B','A') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL COMMENT 'used for Enterprise or Each Branch or Both',
  `f_lov` int(11) NOT NULL,
  `f_active` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_module` (`fk_id_module`,`param_name`),
  KEY `fk_id_module_params` (`fk_id_module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_module_params_lov`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_module_params_lov` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_module_params` int(11) NOT NULL,
  `lov_value` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_active` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_module_params` (`fk_id_module_params`,`lov_value`),
  KEY `fk_id_param_lov` (`fk_id_module_params`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_module_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_module_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_type_name` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `iso_module_type` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `module_type_name` (`module_type_name`),
  UNIQUE KEY `iso_module_type` (`iso_module_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_modules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_name` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `fk_id_module_type` int(11) NOT NULL,
  `f_active` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `module_name` (`module_name`),
  KEY `fk_id_type_modules` (`fk_id_module_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_payment_term_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_payment_term_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_payment_term` int(11) NOT NULL,
  `term_no` smallint(6) NOT NULL,
  `milestone` varchar(30) NOT NULL,
  `pct` decimal(5,2) NOT NULL,
  `due_rule` varchar(30) NOT NULL,
  `due_days` smallint(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_term_line` (`fk_id_payment_term`,`term_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Template lines of a payment term (R-B-27)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_payment_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_payment_terms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `term_short` varchar(100) NOT NULL,
  `term_descr` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payterm` (`term_short`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_photo_ids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_photo_ids` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `photo_name` varchar(150) NOT NULL,
  `country_id` int(11) NOT NULL,
  `photo_iso_code` varchar(80) NOT NULL,
  `f_active` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_ports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_ports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `port_name` varchar(150) NOT NULL,
  `port_code` varchar(255) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `port_type` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_port` (`port_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_price_change_reasons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_price_change_reasons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reason_name` varchar(150) NOT NULL,
  `f_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_active` (`f_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_product_conditions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_product_conditions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `condition_name` varchar(100) NOT NULL,
  `f_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_product_trends`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_product_trends` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `product_trend_name` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `iso_product_trend` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_active` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`product_trend_name`),
  UNIQUE KEY `fk_id_ent_2` (`fk_id_ent`,`iso_product_trend`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_product_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_product_type` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `product_type_name` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `iso_product_type` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_active` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`product_type_name`),
  UNIQUE KEY `fk_id_ent_2` (`fk_id_ent`,`iso_product_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_references`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_references` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reference_heading` varchar(255) NOT NULL,
  `f_active` tinyint(1) NOT NULL DEFAULT 1,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ref_code` varchar(40) DEFAULT NULL,
  `department` varchar(60) DEFAULT NULL,
  `ref_type` varchar(60) DEFAULT NULL,
  `add_rule` enum('ANY','PENDING','ADMIN') NOT NULL DEFAULT 'ANY',
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ref_code` (`fk_id_ent`,`ref_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `cat_references` VALUES
(1,'Shipping modes',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','SHIP_MODE','Sourcing','List','ANY',NULL),
(2,'Ports of loading',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','PORT_LOAD','Sourcing','List','ANY',NULL),
(3,'Ports of arrival',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','PORT_ARRIVE','Sourcing','List','ANY',NULL),
(4,'Carton sizes',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','CARTON_SIZE','Sourcing','List','ANY',NULL),
(5,'Packing types',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','PACKING','Selling','List','ANY',NULL),
(6,'Carrier services',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','CARRIER','Setup','List','ANY',NULL),
(7,'Payment terms',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','PAY_TERMS','Sourcing','List','ANY',NULL),
(8,'Incoterms',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','INCOTERM','Sourcing','List','ANY',NULL),
(9,'Contract types',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','CONTRACT_TYPE','People','List','ANY',NULL),
(10,'ID document types',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','ID_TYPE','People','List','ANY',NULL),
(11,'Trademark offices',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','TM_OFFICE','Products','List','ANY',NULL),
(12,'Nice classes',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','NICE_CLASS','Products','List','ANY',NULL),
(13,'Supplier types',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','SUPPLIER_TYPE','Sourcing','List','ANY',NULL),
(14,'Sample policies',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','SAMPLE_POLICY','Sourcing','List','ANY',NULL),
(15,'CS channels',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','CS_CHANNEL','Selling','List','ANY',NULL),
(16,'Brands',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','BRAND','Products','List','PENDING',NULL),
(17,'Product categories',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','CATEGORY','Products','List','ANY',NULL),
(18,'Priorities',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','PRIORITY','Work','List','ADMIN',NULL),
(19,'Document types',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','DOC_TYPE','Work','List','ANY',NULL),
(20,'Billing cycles',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','BILLING_CYCLE','Money','List','ADMIN',NULL),
(21,'Cost categories',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','COST_CATEGORY','Money','List','PENDING',NULL),
(22,'Return reasons',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','RETURN_REASON','Selling','List','ANY',NULL),
(23,'Return conditions',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','RETURN_CONDITION','Selling','List','ADMIN',NULL),
(24,'Stock adjustment reasons',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','ADJUST_REASON','Stock','List','ADMIN',NULL),
(25,'Account kit kinds',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','KIT_KIND','People','List','ANY',NULL),
(26,'Customer service case types',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','CS_TYPE','Selling','List','ANY',NULL),
(27,'Banks',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','BANK','Money','List','ANY',NULL),
(28,'Countries (extra)',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','COUNTRY','Setup','List','ANY',NULL),
(29,'Currencies (extra)',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','CURRENCY','Setup','List','ADMIN',NULL),
(30,'Roles (extra)',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','ROLE','People','List','ADMIN',NULL),
(31,'Sourcing status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','SOURCING_STATUS','Sourcing','List','ADMIN',NULL),
(32,'Allocation status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','ALLOCATION_STATUS','Sourcing','List','ADMIN',NULL),
(33,'Importer of record',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','IMPORTER_OF_RECORD','Sourcing','List','ADMIN',NULL),
(34,'Customs status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','CUSTOMS_STATUS','Sourcing','List','ADMIN',NULL),
(35,'Doc category',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','DOC_CATEGORY','Sourcing','List','ADMIN',NULL),
(36,'Doc status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','DOC_STATUS','Sourcing','List','ADMIN',NULL),
(37,'Cost type',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','COST_TYPE','Sourcing','List','ADMIN',NULL),
(38,'Receiving qc status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','RECEIVING_QC_STATUS','Sourcing','List','ADMIN',NULL),
(39,'Posting status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','POSTING_STATUS','Sourcing','List','ADMIN',NULL),
(40,'Landing status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','LANDING_STATUS','Sourcing','List','ADMIN',NULL),
(41,'Inventory status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','INVENTORY_STATUS','Sourcing','List','ADMIN',NULL),
(42,'Invest type',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','INVEST_TYPE','Sourcing','List','ADMIN',NULL),
(43,'Investment status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','INVESTMENT_STATUS','Sourcing','List','ADMIN',NULL),
(44,'Investor status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','INVESTOR_STATUS','Sourcing','List','ADMIN',NULL),
(45,'Ownership status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','OWNERSHIP_STATUS','Sourcing','List','ADMIN',NULL),
(46,'Term status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','TERM_STATUS','Sourcing','List','ADMIN',NULL),
(47,'Payment type',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','PAYMENT_TYPE','Sourcing','List','ADMIN',NULL),
(48,'Payment method',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','PAYMENT_METHOD','Sourcing','List','ADMIN',NULL),
(49,'Payment status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','PAYMENT_STATUS','Sourcing','List','ADMIN',NULL),
(50,'Platform alloc status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','PLATFORM_ALLOC_STATUS','Sourcing','List','ADMIN',NULL),
(51,'Po status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','PO_STATUS','Sourcing','List','ADMIN',NULL),
(52,'Production status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','PRODUCTION_STATUS','Sourcing','List','ADMIN',NULL),
(53,'Inspection type',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','INSPECTION_TYPE','Sourcing','List','ADMIN',NULL),
(54,'Qc result',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','QC_RESULT','Sourcing','List','ADMIN',NULL),
(55,'Qc approval',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','QC_APPROVAL','Sourcing','List','ADMIN',NULL),
(56,'Rfq status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','RFQ_STATUS','Sourcing','List','ADMIN',NULL),
(57,'Sample status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','SAMPLE_STATUS','Sourcing','List','ADMIN',NULL),
(58,'Shipping mode',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','SHIPPING_MODE','Sourcing','List','ADMIN',NULL),
(59,'Fcl lcl',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','FCL_LCL','Sourcing','List','ADMIN',NULL),
(60,'Container type',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','CONTAINER_TYPE','Sourcing','List','ADMIN',NULL),
(61,'Shipment status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','SHIPMENT_STATUS','Sourcing','List','ADMIN',NULL),
(62,'Verification status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','VERIFICATION_STATUS','Sourcing','List','ADMIN',NULL),
(63,'Risk level',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','RISK_LEVEL','Sourcing','List','ADMIN',NULL),
(64,'Transfer status',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','TRANSFER_STATUS','Sourcing','List','ADMIN',NULL),
(65,'Port type',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','PORT_TYPE','Sourcing','List','ADMIN',NULL),
(66,'Loss nature',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','LOSS_NATURE','Selling','List','ADMIN',NULL),
(67,'Reminder type',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','REMINDER_TYPE','Selling','List','ADMIN',NULL),
(68,'Colour',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','COLOUR','Products','List','ADMIN',NULL),
(69,'Size',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','SIZE','Products','List','ADMIN',NULL),
(70,'Sourcing platform',1,0,'2026-11-11 23:31:47','2026-11-11 23:31:47','SOURCING_PLATFORM','Sourcing','List','ADMIN',NULL),
(81,'Packaging materials',1,0,'2026-11-11 23:31:48','2026-11-11 23:31:48','PACK_MATERIAL','Products','List','ANY',NULL),
(82,'Certificate and compliance document types',1,0,'2026-11-11 23:31:48','2026-11-11 23:31:48','CERT_TYPE','Products','List','ADMIN',NULL),
(83,'Departments',1,0,'2026-11-11 23:31:48','2026-11-11 23:31:48','DEPARTMENT','People','List','ADMIN',NULL),
(84,'Skills',1,0,'2026-11-11 23:31:48','2026-11-11 23:31:48','SKILL','People','List','ANY',NULL),
(85,'Pay cycles',1,0,'2026-11-11 23:31:48','2026-11-11 23:31:48','PAY_CYCLE','People','List','ADMIN',NULL),
(86,'Languages',1,0,'2026-11-11 23:31:48','2026-11-11 23:31:48','LANGUAGE','Sourcing','List','ANY',NULL),
(87,'Supplier certifications and audits',1,0,'2026-11-11 23:31:48','2026-11-11 23:31:48','SUPPLIER_CERT','Sourcing','List','ANY',NULL),
(88,'Document tags',1,0,'2026-11-11 23:31:48','2026-11-11 23:31:48','DOC_TAG','Work','List','ANY',NULL);
DROP TABLE IF EXISTS `cat_rights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_rights` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_module` int(11) NOT NULL,
  `right_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `iso_right` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_module` (`fk_id_module`,`iso_right`),
  UNIQUE KEY `fk_id_module_2` (`fk_id_module`,`right_name`),
  KEY `idx_id_moule_in_rights` (`fk_id_module`),
  KEY `idx_iso_right_in_rights` (`iso_right`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_social_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_social_media` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `social_media_name` varchar(150) NOT NULL,
  `f_active` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_src_lov`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_src_lov` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `lov_group` varchar(100) NOT NULL,
  `lov_value` varchar(190) NOT NULL,
  `lov_order` decimal(15,4) DEFAULT 0.0000,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lov` (`lov_group`,`lov_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_subscription_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_subscription_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `country_id` int(11) NOT NULL,
  `item_iso_code` varchar(80) NOT NULL,
  `f_active` tinyint(4) NOT NULL,
  `fk_id_kit_kind` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `tag_name` varchar(150) NOT NULL,
  `tag_color` varchar(20) NOT NULL,
  `f_active` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ent_acc_types` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_tbl_pc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_tbl_pc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `pc_number` varchar(150) NOT NULL,
  `hotspot_number` varchar(100) NOT NULL,
  `f_active` tinyint(4) NOT NULL,
  `owner_account_id` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_vendors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_country_id` int(11) DEFAULT NULL,
  `vendor_name` varchar(150) DEFAULT NULL,
  `vendor_company_name` varchar(150) DEFAULT NULL,
  `vendor_telephone` varchar(100) DEFAULT NULL,
  `vendor_address` varchar(255) DEFAULT NULL,
  `vendor_url` varchar(100) DEFAULT NULL,
  `vendor_user_id` varchar(100) DEFAULT NULL,
  `vendor_password` varchar(100) DEFAULT NULL,
  `vendor_bank_details` text DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cat_warehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cat_warehouses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `warehouse_short` varchar(255) DEFAULT NULL,
  `warehouse_name` varchar(255) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cc_provider_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cc_provider_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_cc_provider_id` int(11) NOT NULL,
  `question` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `cc_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cc_providers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `country_id` int(11) NOT NULL,
  `card_provider_name` varchar(200) NOT NULL,
  `f_active` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `crm_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_mp_profile` int(11) NOT NULL,
  `buyer_ref` varchar(120) NOT NULL,
  `display_name` varchar(150) DEFAULT NULL,
  `first_order_date` date DEFAULT NULL,
  `last_order_date` date DEFAULT NULL,
  `f_flagged` tinyint(4) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cust` (`fk_id_ent`,`fk_id_mp_profile`,`buyer_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='One buyer per marketplace profile: orders, returns, cases (R-A_SALES-89)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `csv_generator_columns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `csv_generator_columns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_csv_gen_id` int(11) NOT NULL,
  `ord_date` varchar(90) NOT NULL,
  `order_id` varchar(200) NOT NULL,
  `item_title` text NOT NULL,
  `customer_1` varchar(255) NOT NULL,
  `customer_2` varchar(255) NOT NULL,
  `address_1` text NOT NULL,
  `address_2` text NOT NULL,
  `address_3` text NOT NULL,
  `address_4` text NOT NULL,
  `address_5` text NOT NULL,
  `address_6` text NOT NULL,
  `address_7` text NOT NULL,
  `address_8` text NOT NULL,
  `quantity` varchar(50) NOT NULL,
  `sale_buy_price` varchar(50) NOT NULL,
  `sku` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `csv_generator_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `csv_generator_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `import_name` varchar(200) NOT NULL,
  `system_columns` text NOT NULL,
  `csv_columns` text NOT NULL,
  `import_controller_name` varchar(100) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `csv_generator_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `csv_generator_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `import_name` varchar(600) DEFAULT NULL,
  `import_controller_name` varchar(300) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `dec_recommendations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dec_recommendations` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_enterprise` int(11) NOT NULL,
  `kind` enum('ORDER_NOW','SWITCH_MODE','RAISE_PRICE','LOWER_PRICE','PAUSE_LISTING','REBALANCE_FBA','CANCEL_SUB','CHASE_PAYMENT','CAPITAL_CALL') NOT NULL,
  `entity_table` varchar(64) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `reason` text NOT NULL,
  `impact_gbp` decimal(12,2) DEFAULT NULL,
  `confidence` enum('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'MEDIUM',
  `action_json` text NOT NULL,
  `status` enum('OPEN','APPROVED','REJECTED','EXPIRED','DONE') NOT NULL DEFAULT 'OPEN',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `dedupe_key` varchar(120) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dedupe` (`dedupe_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `dropship_supplier_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dropship_supplier_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `code` varchar(30) NOT NULL,
  `fk_id_supplier` int(11) NOT NULL,
  `fk_id_drp_platform` int(11) DEFAULT NULL,
  `orders_report_type` varchar(40) DEFAULT NULL,
  `match_columns` varchar(200) NOT NULL DEFAULT 'name,address,city,postcode,phone',
  `min_match` tinyint(4) DEFAULT NULL,
  `hold_prefix` varchar(20) DEFAULT NULL,
  `hold_ageing_days` smallint(6) DEFAULT NULL,
  `audit_kind` varchar(16) NOT NULL DEFAULT 'NONE',
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dsp_code` (`fk_id_ent`,`code`),
  KEY `ix_dsp_supplier` (`fk_id_supplier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Dropship-supplier profile (IC-0.7)';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `dropship_supplier_profiles` VALUES
(1,0,'EX_DS_HISTORY',0,NULL,NULL,'name,address,city,postcode,phone',NULL,NULL,NULL,'ORDER_HISTORY',1,'Example row for tests (IC-0.10); supplier set when copied',NULL,'2026-11-12 04:31:48',NULL,NULL),
(2,0,'EX_DS_HOLD',0,NULL,NULL,'name,postcode,phone',2,'HOLD-',NULL,'HOLD_BUY',1,'Example row for tests (IC-0.10); supplier set when copied',NULL,'2026-11-12 04:31:48',NULL,NULL);
DROP TABLE IF EXISTS `drp_import_errors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drp_import_errors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_import` int(11) NOT NULL,
  `row_no` int(11) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `raw_json` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_drp_err_import` (`fk_import`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `drp_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drp_imports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `fk_platform` int(11) NOT NULL,
  `fk_id_marketplace` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `dup_mode` enum('skip','replace') NOT NULL DEFAULT 'skip',
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `inserted_rows` int(11) NOT NULL DEFAULT 0,
  `replaced_rows` int(11) NOT NULL DEFAULT 0,
  `skipped_rows` int(11) NOT NULL DEFAULT 0,
  `merged_rows` int(11) NOT NULL DEFAULT 0,
  `error_rows` int(11) NOT NULL DEFAULT 0,
  `d_from` date DEFAULT NULL,
  `d_to` date DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_by_name` varchar(150) NOT NULL DEFAULT '',
  `uploaded_at` datetime NOT NULL,
  `period_month` tinyint(4) DEFAULT NULL COMMENT 'month chosen in the upload popup (1-12)',
  `period_year` smallint(6) DEFAULT NULL COMMENT 'year chosen in the upload popup',
  PRIMARY KEY (`id`),
  KEY `idx_drp_imp_platform` (`fk_platform`,`uploaded_at`),
  KEY `idx_drp_imp_branch` (`fk_id_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `drp_item_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drp_item_imports` (
  `fk_item` int(11) NOT NULL,
  `fk_import` int(11) NOT NULL,
  `action` enum('added','skipped','replaced') NOT NULL,
  PRIMARY KEY (`fk_item`,`fk_import`),
  KEY `idx_drp_ii_import` (`fk_import`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `drp_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drp_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `fk_platform` int(11) NOT NULL,
  `fk_id_marketplace` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_import` int(11) NOT NULL,
  `fk_import_last` int(11) NOT NULL,
  `dup_key` char(40) NOT NULL,
  `order_no` varchar(100) NOT NULL,
  `d_order` date NOT NULL,
  `item_title` varchar(500) NOT NULL DEFAULT '',
  `item_ref` varchar(150) NOT NULL DEFAULT '',
  `variant` varchar(255) NOT NULL DEFAULT '',
  `qty` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `shipping_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) DEFAULT NULL,
  `order_status` varchar(100) NOT NULL DEFAULT '',
  `tracking_no` varchar(255) NOT NULL DEFAULT '',
  `carrier` varchar(100) NOT NULL DEFAULT '',
  `ship_name` varchar(255) NOT NULL DEFAULT '',
  `ship_address` varchar(500) NOT NULL DEFAULT '',
  `ship_city` varchar(150) NOT NULL DEFAULT '',
  `ship_county` varchar(150) NOT NULL DEFAULT '',
  `ship_postcode` varchar(30) NOT NULL DEFAULT '',
  `ship_country` varchar(100) NOT NULL DEFAULT '',
  `ship_phone` varchar(60) NOT NULL DEFAULT '',
  `raw_json` mediumtext DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `period_month` tinyint(4) DEFAULT NULL COMMENT 'month of the upload that last wrote the row (1-12)',
  `period_year` smallint(6) DEFAULT NULL COMMENT 'year of the upload that last wrote the row',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_drp_items_dup` (`dup_key`),
  KEY `idx_drp_items_date` (`fk_id_ent`,`d_order`),
  KEY `idx_drp_items_platform_date` (`fk_platform`,`d_order`),
  KEY `idx_drp_items_order` (`order_no`),
  KEY `idx_drp_items_ref` (`item_ref`),
  KEY `idx_drp_items_postcode` (`ship_postcode`),
  KEY `idx_drp_items_import` (`fk_import`),
  KEY `idx_drp_items_import_last` (`fk_import_last`),
  KEY `idx_drp_items_branch` (`fk_id_branch`,`d_order`),
  KEY `idx_drp_items_mp` (`fk_id_marketplace`,`d_order`),
  KEY `idx_drp_items_branch_period` (`fk_id_branch`,`period_year`,`period_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `drp_platform_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drp_platform_mappings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `fk_platform` int(11) NOT NULL,
  `mapping_json` text NOT NULL,
  `date_order` varchar(4) NOT NULL DEFAULT 'auto',
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_drp_map_platform` (`fk_id_ent`,`fk_platform`),
  KEY `fk_drp_map_platform` (`fk_platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `drp_platforms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `drp_platforms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform_name` varchar(100) NOT NULL,
  `f_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_drp_platform_name` (`platform_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ebay`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ebay` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_branch` int(11) NOT NULL,
  `sku_ebay` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `Sales_record_number` int(11) DEFAULT NULL,
  `Order_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_username` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_note` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_address_1` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_address_2` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_city` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_county` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_postcode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_country` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_tax_identifier_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_tax_identifier_value` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_phone` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_address_1` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_address_2` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_county` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_postcode` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Item_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Item_title` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Custom_label` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Sold_via_Promoted_listings` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Quantity` varchar(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Sold_for` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Postage_and_packaging` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Item_location` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Item_postcode` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Item_country` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Included_VAT_rate` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Rate` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_reference_name` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_reference_value` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Tax_status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Seller_collected_tax` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_collected_tax` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Electronic_waste_recycling_fee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Mattress_recycling_fee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Battery_recycling_fee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `White_goods_disposal_tax` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Tyre_recycling_fee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Additional_fee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Total_price` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_collected_tax_and_fees_included_in_total` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Payment_method` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Sale_date` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Paid_on_date` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_by_date` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Minimum_estimated_delivery_date` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Maximum_estimated_delivery_date` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Dispatched_on_date` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Feedback_left` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Feedback_received` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `My_item_note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `PayPal_transaction_ID` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Delivery_service` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Tracking_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Transaction_ID` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Variation_details` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Global_Shipping_Programme` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Global_Shipping_Reference_ID` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Click_and_Collect` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Click_and_Collect_Reference_Number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_Plus` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_Fulfilment_Programme` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_id_branch_in_ebay` (`fk_id_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ebay_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ebay_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_audit_main` int(11) NOT NULL,
  `sku_ebay` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `Sales_record_number` int(11) DEFAULT NULL,
  `Order_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_username` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_note` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_address_1` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_address_2` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_city` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_county` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_postcode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_country` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_tax_identifier_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Buyer_tax_identifier_value` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_phone` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_address_1` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_address_2` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_county` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_postcode` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_to_country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Item_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Item_title` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Custom_label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Sold_via_Promoted_listings` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Quantity` int(11) DEFAULT NULL,
  `Sold_for` decimal(10,2) DEFAULT NULL,
  `Postage_and_packaging` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Item_location` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Item_postcode` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Item_country` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Included_VAT_rate` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Rate` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_reference_name` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_reference_value` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Tax_status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Seller_collected_tax` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_collected_tax` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Electronic_waste_recycling_fee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Mattress_recycling_fee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Battery_recycling_fee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `White_goods_disposal_tax` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Tyre_recycling_fee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Additional_fee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Total_price` decimal(11,2) DEFAULT NULL,
  `eBay_collected_tax_and_fees_included_in_total` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Payment_method` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Sale_date` date DEFAULT NULL,
  `Paid_on_date` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Post_by_date` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Minimum_estimated_delivery_date` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Maximum_estimated_delivery_date` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Dispatched_on_date` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Feedback_left` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Feedback_received` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `My_item_note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `PayPal_transaction_ID` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Delivery_service` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Tracking_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Transaction_ID` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Variation_details` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Global_Shipping_Programme` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Global_Shipping_Reference_ID` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Click_and_Collect` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Click_and_Collect_Reference_Number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_Plus` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eBay_Fulfilment_Programme` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `f_local` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_ref_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pur_ref_no_gd_hold` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `tot_pur_amount` decimal(10,2) DEFAULT NULL,
  `item_note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason_by_va` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_audit_main_in_abay_audit` (`fk_id_audit_main`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fba_carton_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fba_carton_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_fba_carton` int(11) NOT NULL,
  `fk_id_product` int(11) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `qty` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fba_item` (`fk_id_fba_carton`,`fk_id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Items of a MIXED carton';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fba_cartons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fba_cartons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_fba_shipment` int(11) NOT NULL,
  `carton_no` smallint(6) NOT NULL,
  `carton_kind` varchar(6) NOT NULL DEFAULT 'SINGLE',
  `fk_id_product` int(11) DEFAULT NULL,
  `qty` int(11) DEFAULT NULL,
  `weight_kg` decimal(8,2) NOT NULL,
  `length_cm` decimal(8,2) NOT NULL,
  `width_cm` decimal(8,2) NOT NULL,
  `height_cm` decimal(8,2) NOT NULL,
  `label_status` varchar(12) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fba_carton` (`fk_id_fba_shipment`,`carton_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Cartons of an inbound shipment';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fba_shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fba_shipments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `shipment_ref` varchar(60) NOT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `fk_id_from_location` int(11) NOT NULL,
  `fk_id_dest_location` int(11) NOT NULL,
  `d_created` date NOT NULL,
  `d_shipped` date DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `tracking_no` varchar(60) DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'DRAFT',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fba_ship` (`fk_id_branch`,`shipment_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Inbound shipment to a marketplace fulfilment centre';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_ads_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_ads_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `ad_date` date NOT NULL,
  `campaign_id` varchar(60) NOT NULL,
  `campaign_name` varchar(190) DEFAULT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `spend` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sales` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_ads` (`branch_id`,`ad_date`,`campaign_id`,`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_alert_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_alert_rules` (
  `code` varchar(10) NOT NULL,
  `label` varchar(190) NOT NULL,
  `to_roles` varchar(120) NOT NULL,
  `channels` varchar(60) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `ref_key` varchar(190) NOT NULL COMMENT 'rule + record; one open alert per key',
  `message` varchar(500) NOT NULL,
  `status` enum('OPEN','RESOLVED') NOT NULL DEFAULT 'OPEN',
  `first_at` datetime NOT NULL,
  `last_sent_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `task_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_alert_open` (`ref_key`),
  KEY `ix_alert_status` (`status`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL DEFAULT 0,
  `action` varchar(40) NOT NULL,
  `table_name` varchar(60) NOT NULL,
  `record_id` int(11) NOT NULL DEFAULT 0,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_audit_rec` (`table_name`,`record_id`),
  KEY `ix_audit_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_bank_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_bank_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `bank_name` varchar(80) NOT NULL,
  `account_label` varchar(80) NOT NULL,
  `currency` char(3) DEFAULT NULL,
  `opening_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `opening_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `ix_ba_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_bank_line_splits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_bank_line_splits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_bank_line` int(11) NOT NULL,
  `split_no` smallint(6) NOT NULL,
  `target_type` varchar(20) NOT NULL,
  `fk_id_freight_cost` int(11) DEFAULT NULL,
  `fk_id_customs` int(11) DEFAULT NULL,
  `fk_id_expense` int(11) DEFAULT NULL,
  `category_code` varchar(30) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `amount_gbp` decimal(14,2) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_split` (`fk_id_bank_line`,`split_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='One bank line split across cost rows (IC-15)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_bank_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_bank_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `bank_account_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `txn_date` date NOT NULL,
  `amount` decimal(14,2) NOT NULL COMMENT '+ in / - out',
  `currency` char(3) DEFAULT NULL,
  `amount_gbp` decimal(14,2) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `counterparty` varchar(190) DEFAULT NULL,
  `bank_txn_id` varchar(80) NOT NULL,
  `category_code` varchar(40) DEFAULT NULL COMMENT 'Reference Register (Finance) type code',
  `reference_no` varchar(80) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL COMMENT 'tag a cost to a marketplace',
  `vat_flag` tinyint(1) NOT NULL DEFAULT 0,
  `matched_to_type` varchar(30) DEFAULT NULL COMMENT 'SETTLEMENT / SUPPLIER_PAYMENT / WITHDRAWAL / INTERCOMPANY / RESERVE / LOAN / CAPITAL_CALL',
  `matched_to_id` varchar(60) DEFAULT NULL,
  `match_status` enum('UNMATCHED','AUTO','MANUAL','IGNORED') NOT NULL DEFAULT 'UNMATCHED',
  `match_rule` varchar(20) DEFAULT NULL,
  `matched_by` int(11) DEFAULT NULL,
  `matched_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_bt` (`bank_account_id`,`bank_txn_id`),
  KEY `ix_bt_date` (`company_id`,`txn_date`),
  KEY `ix_bt_status` (`match_status`),
  KEY `ix_bt_list` (`company_id`,`match_status`,`txn_date`,`id`),
  KEY `ix_bt_match` (`matched_to_type`,`matched_to_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_budgets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `year` smallint(6) NOT NULL,
  `month` tinyint(4) NOT NULL,
  `category_code` varchar(40) NOT NULL COMMENT 'Reference Register (Finance) type code',
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_budget` (`company_id`,`year`,`month`,`category_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_capital_call_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_capital_call_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `call_id` int(11) NOT NULL,
  `investor_id` int(11) NOT NULL,
  `amount_accepted` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('SENT','ACCEPTED','DECLINED','RECEIVED') NOT NULL DEFAULT 'SENT',
  `bank_txn_id` int(11) DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_ccr` (`call_id`,`investor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_capital_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_capital_calls` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `po_id` int(11) DEFAULT NULL,
  `amount_requested` decimal(14,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `due_date` date DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_cash_commitments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_cash_commitments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_enterprise` int(11) NOT NULL,
  `source_table` varchar(64) NOT NULL,
  `source_id` int(11) NOT NULL,
  `what` varchar(120) NOT NULL,
  `due_on` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `amount_gbp` decimal(12,2) NOT NULL,
  `status` enum('PLANNED','COMMITTED','PAID','CANCELLED') NOT NULL DEFAULT 'PLANNED',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fk_id_payee` int(11) DEFAULT NULL,
  `category_code` varchar(30) DEFAULT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  `fk_id_va_statement` int(11) DEFAULT NULL,
  `fk_id_bank_line` int(11) DEFAULT NULL,
  `dedupe_key` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_commit_dedupe` (`dedupe_key`),
  KEY `k_ent_due` (`fk_id_enterprise`,`due_on`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `direction` enum('IN','OUT','BOTH') NOT NULL,
  `profit_role` enum('REVENUE','COGS','VARIABLE','FIXED','BALANCE_SHEET','TAX','TRANSFER','PARTNER','NONE') NOT NULL,
  `f_vat_card` tinyint(4) NOT NULL DEFAULT 0,
  `f_system` tinyint(4) NOT NULL DEFAULT 0,
  `sort_no` smallint(6) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Finance categories with direction and profit role';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `fin_categories` VALUES
(1,'SALES_PAYOUT','Marketplace payout','IN','REVENUE',0,1,10,1),
(2,'REFUND_RECEIVED','Refund received','IN','VARIABLE',0,1,20,1),
(3,'OWN_TRANSFER_IN','Own transfer in','IN','TRANSFER',0,1,30,1),
(4,'OWN_TRANSFER_OUT','Own transfer out','OUT','TRANSFER',0,1,40,1),
(5,'INVOICE_RECEIPT','Invoice receipt','IN','PARTNER',0,1,50,1),
(6,'INVOICE_PAYMENT','Invoice payment','OUT','PARTNER',0,1,60,1),
(7,'CAPITAL_IN','Partner capital in','IN','PARTNER',0,1,70,1),
(8,'WITHDRAWAL','Partner withdrawal','OUT','PARTNER',0,1,80,1),
(9,'SUPPLIER_PAYMENT','Supplier payment (stock)','OUT','BALANCE_SHEET',0,1,90,1),
(10,'SAMPLES','Samples','OUT','VARIABLE',0,1,100,1),
(11,'STOCK_LOSS','Stock loss','OUT','COGS',0,1,110,1),
(12,'IMPORT_FREIGHT','Import freight','OUT','BALANCE_SHEET',0,1,120,1),
(13,'IMPORT_DUTY','Import duty','OUT','BALANCE_SHEET',0,1,130,1),
(14,'DUTY_VAT','Import VAT (reclaim)','OUT','TAX',1,1,140,1),
(15,'VA_SHARE_PAYOUT','VA share payout','OUT','NONE',0,1,150,1),
(16,'SALARY_VA','VA salary','OUT','FIXED',0,1,160,1),
(17,'SPLIT','Split line (see parts)','BOTH','NONE',0,1,170,1),
(18,'HMRC_VAT','VAT paid to the tax office','OUT','TAX',1,1,180,1),
(19,'HMRC_CT','Corporation tax','OUT','TAX',0,1,190,1),
(20,'HMRC_PAYE','Payroll tax','OUT','TAX',0,1,200,1),
(21,'ADS_EXTERNAL','External advertising','OUT','VARIABLE',0,1,210,1),
(22,'POSTAGE','Postage','OUT','VARIABLE',0,0,220,1),
(23,'PACKAGING','Packaging','OUT','VARIABLE',0,0,230,1),
(24,'SOFTWARE','Software and tools','OUT','FIXED',0,0,240,1),
(25,'WAREHOUSE','Warehouse','OUT','FIXED',0,0,250,1),
(26,'BANK_FEES','Bank and FX fees','OUT','FIXED',0,0,260,1),
(27,'PROFESSIONAL','Professional fees','OUT','FIXED',0,0,270,1),
(28,'SALARIES_OTHER','Other salaries','OUT','FIXED',0,0,280,1),
(29,'LOAN_IN','Loan received','IN','BALANCE_SHEET',0,1,290,1),
(30,'LOAN_OUT','Loan repaid or given','OUT','BALANCE_SHEET',0,1,300,1),
(31,'OTHER_IN','Other income','IN','REVENUE',0,0,310,1),
(32,'OTHER_OUT','Other cost','OUT','FIXED',0,0,320,1),
(33,'SUPPLIER_REFUND','Supplier refund','IN','NONE',0,1,330,1),
(34,'INTERCOMPANY_IN','Inter-company in','IN','TRANSFER',0,1,340,1),
(35,'INTERCOMPANY_OUT','Inter-company out','OUT','TRANSFER',0,1,350,1),
(36,'FREIGHT','Freight and delivery (not import)','OUT','VARIABLE',0,1,360,1),
(37,'PLATFORM_FEE','Platform fee (outside the settlement)','OUT','VARIABLE',0,1,370,1),
(38,'PPC','Advertising inside the settlement','OUT','NONE',0,1,380,1),
(39,'SUBSCRIPTION','Subscription','OUT','FIXED',0,0,390,1),
(40,'OFFICE','Office','OUT','FIXED',0,0,400,1),
(41,'TAX_RESERVE','Tax reserve transfer','OUT','NONE',0,1,410,1);
DROP TABLE IF EXISTS `fin_company_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_company_settings` (
  `company_id` int(11) NOT NULL COMMENT 'fk_id_ent of the existing entity',
  `display_name` varchar(120) DEFAULT NULL COMMENT 'used ONLY when fin_records.company has no table',
  `vat_rate` decimal(6,3) NOT NULL DEFAULT 0.000 COMMENT 'percent, part of the tax reserve',
  `corp_tax_rate` decimal(6,3) NOT NULL DEFAULT 19.000,
  `free_cash_alert_limit` decimal(14,2) NOT NULL DEFAULT 10000.00,
  `payout_tolerance` decimal(10,2) NOT NULL DEFAULT 1.00,
  `distribution_schedule` enum('PER_PERIOD','MONTHLY','QUARTERLY') NOT NULL DEFAULT 'PER_PERIOD',
  `slow_days` int(11) NOT NULL DEFAULT 90,
  `dead_days` int(11) NOT NULL DEFAULT 180,
  `fx_source` varchar(60) NOT NULL DEFAULT 'daily table',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `d_expense` date NOT NULL,
  `category_code` varchar(30) NOT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_payee` int(11) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `fk_id_currency` int(11) NOT NULL,
  `exchange_rate` decimal(18,6) DEFAULT NULL,
  `amount_gbp` decimal(14,2) DEFAULT NULL,
  `vat_amount` decimal(14,2) DEFAULT NULL,
  `fk_id_paid_from` int(11) NOT NULL,
  `fk_id_bank_line` int(11) DEFAULT NULL,
  `source` varchar(10) NOT NULL DEFAULT 'MANUAL',
  `receipt_url` varchar(500) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_exp_date` (`fk_id_ent`,`d_expense`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Company expenses';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_external_profit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_external_profit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `ext_date` date NOT NULL,
  `sku` varchar(80) NOT NULL,
  `sb_sales` decimal(12,2) DEFAULT NULL,
  `sb_profit` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_ext` (`branch_id`,`ext_date`,`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_fba_stock_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_fba_stock_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `snapshot_date` date NOT NULL,
  `sku` varchar(80) NOT NULL,
  `fulfillable` int(11) NOT NULL DEFAULT 0,
  `reserved` int(11) NOT NULL DEFAULT 0,
  `inbound_working` int(11) NOT NULL DEFAULT 0,
  `inbound_shipped` int(11) NOT NULL DEFAULT 0,
  `inbound_receiving` int(11) NOT NULL DEFAULT 0,
  `unfulfillable` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_fba` (`branch_id`,`snapshot_date`,`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_fx_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_fx_rates` (
  `rate_date` date NOT NULL,
  `currency` char(3) NOT NULL,
  `rate_to_gbp` decimal(12,6) NOT NULL,
  `source` varchar(40) NOT NULL DEFAULT 'manual',
  PRIMARY KEY (`rate_date`,`currency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_import_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_type` varchar(30) NOT NULL,
  `company_id` int(11) NOT NULL DEFAULT 0,
  `branch_id` int(11) DEFAULT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL DEFAULT '',
  `file_hash` char(40) NOT NULL DEFAULT '',
  `rows_total` int(11) NOT NULL DEFAULT 0,
  `rows_new` int(11) NOT NULL DEFAULT 0,
  `rows_duplicate` int(11) NOT NULL DEFAULT 0,
  `rows_error` int(11) NOT NULL DEFAULT 0,
  `period_from` date DEFAULT NULL,
  `period_to` date DEFAULT NULL,
  `imported_by` int(11) NOT NULL DEFAULT 0,
  `imported_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ib_type` (`report_type`,`branch_id`,`imported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_import_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_import_mappings` (
  `report_type` varchar(30) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0 COMMENT '0 = any store of that type',
  `mapping_json` text NOT NULL COMMENT 'field => column header',
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`report_type`,`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_investor_agreements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_investor_agreements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `investor_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `partner_type` enum('ACTIVE','SLEEPING','WORKING') NOT NULL DEFAULT 'SLEEPING',
  `scope` enum('POOL','MARKETPLACE','PO') NOT NULL DEFAULT 'POOL',
  `branch_id` int(11) DEFAULT NULL COMMENT 'scope MARKETPLACE: t_branches.id',
  `po_id` int(11) DEFAULT NULL COMMENT 'scope PO: t_src_po.id',
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL COMMENT 'NULL = current',
  `capital_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `profit_share_pct` decimal(6,3) NOT NULL DEFAULT 0.000,
  `management_fee_pct` decimal(6,3) NOT NULL DEFAULT 0.000,
  `payout_schedule` enum('PER_PERIOD','MONTHLY','QUARTERLY','ON_SELL_THROUGH') NOT NULL DEFAULT 'PER_PERIOD',
  `status` enum('DRAFT','ACTIVE','ENDED') NOT NULL DEFAULT 'DRAFT',
  `ended_reason` varchar(190) DEFAULT NULL,
  `agreement_doc_path` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `stacking_rule` varchar(12) NOT NULL DEFAULT 'SPLIT_UNITS',
  `stack_cap_pct` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_agr_investor` (`investor_id`,`company_id`,`effective_from`),
  KEY `ix_agr_scope` (`scope`,`branch_id`,`po_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_investor_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_investor_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `investor_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `period_id` int(11) DEFAULT NULL,
  `entry_date` date NOT NULL,
  `type` enum('CAPITAL_IN','PROFIT','MANAGEMENT_FEE','WITHDRAWAL','REINVEST','ADJUSTMENT','LOAN') NOT NULL,
  `amount` decimal(14,2) NOT NULL COMMENT '+ increases the partner balance',
  `reference_no` varchar(80) DEFAULT NULL,
  `statement_id` int(11) DEFAULT NULL,
  `bank_txn_id` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_led_investor` (`investor_id`,`company_id`,`entry_date`),
  KEY `ix_led_period` (`period_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_legacy_migration`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_legacy_migration` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_at` datetime NOT NULL,
  `run_by` int(11) NOT NULL,
  `mode` enum('DRY_RUN','APPLIED') NOT NULL,
  `legacy_table` varchar(64) NOT NULL,
  `legacy_id` int(11) NOT NULL,
  `target_table` varchar(64) NOT NULL,
  `target_id` int(11) DEFAULT NULL,
  `detail` varchar(400) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_legacy` (`legacy_table`,`legacy_id`,`target_table`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_loans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `counterparty` varchar(120) NOT NULL,
  `direction` enum('GIVEN','TAKEN') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `currency` char(3) DEFAULT NULL,
  `start_date` date NOT NULL,
  `repayment_schedule` text DEFAULT NULL COMMENT 'JSON',
  `balance` decimal(14,2) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_lot_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_lot_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_lot` int(11) NOT NULL,
  `fk_id_location` int(11) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lot_loc` (`fk_id_lot`,`fk_id_location`),
  KEY `ix_loc` (`fk_id_location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_lot_consumption`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_lot_consumption` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `period_id` int(11) NOT NULL,
  `settlement_line_id` int(11) NOT NULL,
  `lot_id` int(11) NOT NULL,
  `sku` varchar(80) NOT NULL,
  `qty` int(11) NOT NULL,
  `unit_cost_gbp` decimal(12,4) NOT NULL,
  `fk_id_sale` bigint(20) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `fk_id_movement` bigint(20) DEFAULT NULL,
  `consumed_on` date DEFAULT NULL,
  `fk_id_reverses` int(11) DEFAULT NULL,
  `reason` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_lc_period` (`period_id`,`sku`),
  KEY `ix_lc_lot` (`lot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_lots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_lots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `sku` varchar(80) NOT NULL,
  `po_id` int(11) DEFAULT NULL COMMENT 't_src_po.id (PO-scoped agreements)',
  `source` enum('GRN','OPENING','MANUAL','PIPELINE','QUICK','OLD_ENTRY','RETURN','SPLIT') NOT NULL,
  `source_id` int(11) DEFAULT NULL COMMENT 'GRN id / opening balance id',
  `received_date` date NOT NULL,
  `qty` int(11) NOT NULL,
  `qty_left` int(11) NOT NULL,
  `unit_cost_gbp` decimal(12,4) NOT NULL,
  `created_at` datetime NOT NULL,
  `lot_code` varchar(30) DEFAULT NULL,
  `fk_id_product` int(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `fk_id_receipt` int(11) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `landed_status` enum('PROVISIONAL','FINAL') NOT NULL DEFAULT 'PROVISIONAL',
  `visibility` enum('POOL','PRIVATE') NOT NULL DEFAULT 'POOL',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_lot_src` (`source`,`source_id`,`sku`),
  UNIQUE KEY `uq_lot_code` (`lot_code`),
  KEY `ix_lot_fifo` (`company_id`,`sku`,`received_date`,`id`),
  KEY `ix_fifo` (`company_id`,`fk_id_product`,`received_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_marketplace_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_marketplace_settings` (
  `branch_id` int(11) NOT NULL COMMENT 't_branches.id',
  `payout_currency` char(3) DEFAULT NULL,
  `payout_bank_account_id` int(11) DEFAULT NULL,
  `seller_id` varchar(60) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `investor_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `kind` varchar(30) NOT NULL,
  `channel` varchar(20) NOT NULL,
  `text` varchar(600) NOT NULL,
  `event_key` varchar(190) NOT NULL,
  `status` enum('QUEUED','SENT','FAILED') NOT NULL DEFAULT 'QUEUED',
  `created_at` datetime NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_msg_event` (`event_key`,`channel`),
  KEY `ix_msg_inv` (`investor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_onboarding`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_onboarding` (
  `investor_id` int(11) NOT NULL,
  `master_entry` tinyint(1) NOT NULL DEFAULT 0,
  `agreement_signed` tinyint(1) NOT NULL DEFAULT 0,
  `id_proof` tinyint(1) NOT NULL DEFAULT 0,
  `capital_received` tinyint(1) NOT NULL DEFAULT 0,
  `scope_entered` tinyint(1) NOT NULL DEFAULT 0,
  `login_created` tinyint(1) NOT NULL DEFAULT 0,
  `wa_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `email_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`investor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_opening_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_opening_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `as_of_date` date NOT NULL,
  `type` enum('BANK','PARTNER_CAPITAL','STOCK_VALUE','SUPPLIER_DUE','MARKETPLACE_RESERVE','LOAN','OPENING_LOT') NOT NULL,
  `ref_id` int(11) DEFAULT NULL COMMENT 'bank account / investor / supplier / loan id',
  `sku` varchar(80) DEFAULT NULL COMMENT 'OPENING_LOT',
  `qty` int(11) DEFAULT NULL COMMENT 'OPENING_LOT',
  `unit_cost` decimal(12,4) DEFAULT NULL COMMENT 'OPENING_LOT landed cost per unit GBP',
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ob_company` (`company_id`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_opening_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_opening_locks` (
  `company_id` int(11) NOT NULL,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_order_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_order_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `amazon_order_id` varchar(40) NOT NULL,
  `sku` varchar(80) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `order_date` date DEFAULT NULL,
  `order_status` varchar(30) DEFAULT NULL,
  `item_price` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_ol` (`branch_id`,`amazon_order_id`,`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_partner_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_partner_users` (
  `user_id` int(11) NOT NULL COMMENT 't_account.id',
  `investor_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `ux_pu_investor` (`investor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `settlement_id` varchar(40) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `deposit_date` date DEFAULT NULL,
  `status` enum('OPEN','CHECKED','LOCKED') NOT NULL DEFAULT 'OPEN',
  `owner_note` varchar(255) DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `statement_version` int(11) NOT NULL DEFAULT 0,
  `gross_sales` decimal(14,2) DEFAULT NULL,
  `refunds` decimal(14,2) DEFAULT NULL,
  `fees` decimal(14,2) DEFAULT NULL,
  `ppc` decimal(14,2) DEFAULT NULL,
  `reimbursements` decimal(14,2) DEFAULT NULL,
  `net_payout` decimal(14,2) DEFAULT NULL,
  `cogs` decimal(14,2) DEFAULT NULL,
  `variable_costs` decimal(14,2) DEFAULT NULL,
  `fixed_costs_share` decimal(14,2) DEFAULT NULL,
  `fx_gain_loss` decimal(14,2) DEFAULT NULL,
  `net_profit` decimal(14,2) DEFAULT NULL,
  `tax_reserve` decimal(14,2) DEFAULT NULL,
  `distributable` decimal(14,2) DEFAULT NULL,
  `profit_route` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_period` (`branch_id`,`settlement_id`),
  KEY `ix_period_company` (`company_id`,`period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_product_period`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_product_period` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `period_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `sku` varchar(80) NOT NULL,
  `units_sold` int(11) NOT NULL DEFAULT 0,
  `gross_sales` decimal(14,2) NOT NULL DEFAULT 0.00,
  `refunds` decimal(14,2) NOT NULL DEFAULT 0.00,
  `fees` decimal(14,2) NOT NULL DEFAULT 0.00,
  `ppc` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cogs_fifo` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cogs_dropship` decimal(14,2) NOT NULL DEFAULT 0.00,
  `net_profit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `margin_pct` decimal(8,2) DEFAULT NULL,
  `roi_pct` decimal(8,2) DEFAULT NULL,
  `refund_rate_pct` decimal(8,2) DEFAULT NULL,
  `units_on_hand_end` int(11) DEFAULT NULL,
  `stock_days` int(11) DEFAULT NULL,
  `cost_missing_units` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_pp` (`period_id`,`sku`),
  KEY `ix_pp_sku` (`company_id`,`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_reimbursement_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_reimbursement_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `reimbursement_id` varchar(40) NOT NULL,
  `approval_date` date DEFAULT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `reason` varchar(120) DEFAULT NULL,
  `amount_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `case_status` varchar(30) DEFAULT 'OPEN',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_reimb` (`branch_id`,`reimbursement_id`,`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_returns_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_returns_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `order_id` varchar(40) NOT NULL,
  `sku` varchar(80) NOT NULL,
  `return_date` date NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `disposition` varchar(40) DEFAULT NULL,
  `reason` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_ret` (`branch_id`,`order_id`,`sku`,`return_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_sale_profit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_sale_profit` (
  `fk_id_sale` int(11) NOT NULL,
  `computed_at` datetime NOT NULL,
  `revenue_gbp` decimal(12,2) NOT NULL,
  `cost_gbp` decimal(12,2) DEFAULT NULL,
  `fees_gbp` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ads_gbp` decimal(12,2) NOT NULL DEFAULT 0.00,
  `postage_gbp` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_gbp` decimal(12,2) NOT NULL DEFAULT 0.00,
  `va_share_gbp` decimal(12,2) NOT NULL DEFAULT 0.00,
  `handler_gbp` decimal(12,2) NOT NULL DEFAULT 0.00,
  `profit_gbp` decimal(12,2) DEFAULT NULL,
  `f_final` tinyint(4) NOT NULL DEFAULT 0,
  `missing_json` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`fk_id_sale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_scenarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_scenarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_enterprise` int(11) NOT NULL,
  `title` varchar(120) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `assumptions_json` text NOT NULL,
  `result_json` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` varchar(190) NOT NULL,
  `label` varchar(190) NOT NULL DEFAULT '',
  `sort` int(11) NOT NULL DEFAULT 0,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_settlement_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_settlement_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `settlement_id` varchar(40) NOT NULL,
  `settlement_start` date DEFAULT NULL,
  `settlement_end` date DEFAULT NULL,
  `deposit_date` date DEFAULT NULL,
  `total_amount` decimal(14,2) DEFAULT NULL COMMENT 'settlement total line (deposit)',
  `transaction_type` varchar(40) NOT NULL DEFAULT '',
  `order_id` varchar(40) NOT NULL DEFAULT '',
  `sku` varchar(80) NOT NULL DEFAULT '',
  `quantity` int(11) NOT NULL DEFAULT 0,
  `amount_type` varchar(60) NOT NULL DEFAULT '',
  `amount_description` varchar(120) NOT NULL DEFAULT '',
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) DEFAULT NULL,
  `posted_date` date DEFAULT NULL,
  `bucket` enum('SALE','SHIPPING','TAX','REFUND','FEE','PPC','REIMBURSEMENT','RESERVE','OTHER','TOTAL') NOT NULL DEFAULT 'OTHER',
  `row_hash` char(40) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_sl` (`branch_id`,`settlement_id`,`row_hash`),
  KEY `ix_sl_period` (`branch_id`,`settlement_end`),
  KEY `ix_sl_sku` (`branch_id`,`settlement_id`,`sku`),
  KEY `ix_sl_bucket` (`branch_id`,`settlement_id`,`bucket`),
  KEY `ix_sl_company` (`company_id`,`bucket`,`posted_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_statement_acks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_statement_acks` (
  `statement_id` int(11) NOT NULL,
  `investor_id` int(11) NOT NULL,
  `accepted_at` datetime NOT NULL,
  PRIMARY KEY (`statement_id`,`investor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_statement_queries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_statement_queries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `statement_id` int(11) NOT NULL,
  `investor_id` int(11) NOT NULL,
  `question` text NOT NULL,
  `answer` text DEFAULT NULL,
  `task_id` int(11) DEFAULT NULL COMMENT 'Task Management task when that module is installed',
  `asked_at` datetime NOT NULL,
  `answered_at` datetime DEFAULT NULL,
  `answered_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_statements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_statements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `period_id` int(11) NOT NULL,
  `investor_id` int(11) DEFAULT NULL COMMENT 'NULL = company statement',
  `version` int(11) NOT NULL DEFAULT 1,
  `superseded` tinyint(1) NOT NULL DEFAULT 0,
  `file_path` varchar(255) NOT NULL,
  `file_type` enum('PDF','HTML') NOT NULL DEFAULT 'HTML',
  `partner_profit` decimal(14,2) DEFAULT NULL,
  `partner_balance` decimal(14,2) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_st_period` (`period_id`,`investor_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_stock_moves`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_stock_moves` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `type` enum('PRODUCTION','TRANSIT','DAMAGED','WRITTEN_OFF') NOT NULL,
  `sku` varchar(80) NOT NULL,
  `qty` int(11) NOT NULL,
  `unit_cost_gbp` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `po_id` int(11) DEFAULT NULL,
  `shipment_id` int(11) DEFAULT NULL,
  `ref` varchar(80) DEFAULT NULL,
  `move_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `status` enum('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  `closed_date` date DEFAULT NULL,
  `closed_reason` varchar(200) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `k_co_type` (`company_id`,`type`,`status`),
  KEY `k_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_stock_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_stock_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `snapshot_date` date NOT NULL,
  `company_id` int(11) NOT NULL,
  `sku` varchar(80) NOT NULL,
  `units_on_hand_warehouse` int(11) NOT NULL DEFAULT 0,
  `units_at_amazon` int(11) NOT NULL DEFAULT 0,
  `units_in_transit` int(11) NOT NULL DEFAULT 0,
  `units_in_production` int(11) NOT NULL DEFAULT 0,
  `units_damaged` int(11) NOT NULL DEFAULT 0,
  `units_returned_pending` int(11) NOT NULL DEFAULT 0,
  `units_written_off` int(11) NOT NULL DEFAULT 0,
  `units_lost_at_amazon` int(11) NOT NULL DEFAULT 0,
  `landed_cost_per_unit` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `value_sellable` decimal(14,2) NOT NULL DEFAULT 0.00,
  `value_in_production` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'at PO unit cost',
  `value_in_transit` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'at landed cost of the shipment',
  `value_damaged` decimal(14,2) NOT NULL DEFAULT 0.00,
  `value_returned` decimal(14,2) NOT NULL DEFAULT 0.00,
  `value_written_off` decimal(14,2) NOT NULL DEFAULT 0.00,
  `days_since_last_sale` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_snap` (`snapshot_date`,`company_id`,`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_storage_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_storage_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `month` char(7) NOT NULL COMMENT 'YYYY-MM',
  `asin` varchar(20) NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_storage` (`branch_id`,`month`,`asin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_supplier_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_supplier_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `supplier_name` varchar(120) NOT NULL,
  `po_id` int(11) DEFAULT NULL,
  `po_ref` varchar(60) DEFAULT NULL,
  `milestone` varchar(60) DEFAULT NULL,
  `due_date` date NOT NULL,
  `amount_gbp` decimal(14,2) NOT NULL,
  `paid_gbp` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ss_due` (`company_id`,`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fin_withdrawals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_withdrawals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `investor_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `currency` char(3) DEFAULT NULL,
  `requested_by` int(11) NOT NULL,
  `requested_at` datetime NOT NULL,
  `status` enum('REQUESTED','APPROVED','REJECTED','PAID') NOT NULL DEFAULT 'REQUESTED',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `bank_txn_id` int(11) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `task_id` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `fk_id_pay_to_bank` int(11) DEFAULT NULL,
  `d_needed_by` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_wd_investor` (`investor_id`,`status`),
  KEY `ix_wd_company` (`company_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `icc_freight_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `icc_freight_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ship_mode` enum('AIR','SEA','TRUCK','RAIL','COURIER') NOT NULL,
  `per_kg` decimal(10,4) DEFAULT NULL,
  `per_cbm` decimal(10,2) DEFAULT NULL,
  `min_charge` decimal(10,2) NOT NULL DEFAULT 0.00,
  `days_default` smallint(6) NOT NULL,
  `currency` char(3) DEFAULT NULL,
  `valid_from` date NOT NULL,
  `fk_id_forwarder` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `k_mode` (`ship_mode`,`valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `icc_freight_rates` VALUES
(1,'AIR',5.2000,NULL,60.00,14,'GBP','2026-01-01',NULL),
(2,'SEA',NULL,95.00,180.00,60,'GBP','2026-01-01',NULL),
(3,'TRUCK',NULL,140.00,150.00,33,'GBP','2026-01-01',NULL),
(4,'RAIL',NULL,120.00,150.00,25,'GBP','2026-01-01',NULL),
(5,'COURIER',7.5000,NULL,25.00,7,'GBP','2026-01-01',NULL);
DROP TABLE IF EXISTS `icc_holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `icc_holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `country` char(2) NOT NULL DEFAULT 'CN',
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `add_days` smallint(6) NOT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `icc_holidays` VALUES
(1,'Chinese New Year 2027','CN','2027-02-01','2027-02-24',25,1),
(2,'Golden Week 2026','CN','2026-10-01','2026-10-08',7,1),
(3,'Golden Week 2027','CN','2027-10-01','2027-10-08',7,1);
DROP TABLE IF EXISTS `icc_inputs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `icc_inputs` (
  `fk_id_product` int(11) NOT NULL,
  `fk_id_enterprise` int(11) NOT NULL,
  `monthly_sales` int(11) DEFAULT NULL,
  `season_factor` decimal(5,2) NOT NULL DEFAULT 1.00,
  `growth_pct` decimal(6,2) NOT NULL DEFAULT 0.00,
  `safety_days` smallint(6) NOT NULL DEFAULT 14,
  `fulfil` enum('FBM','FBA','BOTH') NOT NULL DEFAULT 'FBM',
  `unit_weight_g` int(11) DEFAULT NULL,
  `packed_weight_g` int(11) DEFAULT NULL,
  `unit_l_cm` decimal(8,2) DEFAULT NULL,
  `unit_w_cm` decimal(8,2) DEFAULT NULL,
  `unit_h_cm` decimal(8,2) DEFAULT NULL,
  `vol_divisor` int(11) NOT NULL DEFAULT 5000,
  `units_per_carton` int(11) DEFAULT NULL,
  `carton_l_cm` decimal(8,2) DEFAULT NULL,
  `carton_w_cm` decimal(8,2) DEFAULT NULL,
  `carton_h_cm` decimal(8,2) DEFAULT NULL,
  `carton_kg` decimal(8,2) DEFAULT NULL,
  `d_order` smallint(6) NOT NULL DEFAULT 2,
  `d_prod` smallint(6) DEFAULT NULL,
  `d_to_fwd` smallint(6) NOT NULL DEFAULT 3,
  `d_fwd` smallint(6) NOT NULL DEFAULT 5,
  `ship_mode` enum('AIR','SEA','TRUCK','RAIL','COURIER') NOT NULL DEFAULT 'SEA',
  `d_transit` smallint(6) DEFAULT NULL,
  `d_clear` smallint(6) NOT NULL DEFAULT 5,
  `d_fba` smallint(6) NOT NULL DEFAULT 0,
  `f_holiday_cal` tinyint(4) NOT NULL DEFAULT 1,
  `fk_id_supplier` int(11) DEFAULT NULL,
  `fk_id_forwarder` int(11) DEFAULT NULL,
  `unit_cost` decimal(12,4) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `moq` int(11) DEFAULT NULL,
  `deposit_pct` decimal(5,2) NOT NULL DEFAULT 30.00,
  `sale_price` decimal(12,2) DEFAULT NULL,
  `margin_unit` decimal(12,4) DEFAULT NULL,
  `fk_id_paid_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fk_id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `icc_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `icc_results` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_product` int(11) NOT NULL,
  `run_at` datetime NOT NULL DEFAULT current_timestamp(),
  `monthly_used` int(11) NOT NULL,
  `daily` decimal(10,3) NOT NULL,
  `cycle_days` smallint(6) NOT NULL,
  `holiday_add` smallint(6) NOT NULL DEFAULT 0,
  `holiday_name` varchar(40) DEFAULT NULL,
  `reorder_point` int(11) NOT NULL,
  `order_qty_raw` int(11) NOT NULL,
  `order_qty` int(11) NOT NULL,
  `cartons` int(11) NOT NULL,
  `cbm` decimal(10,3) NOT NULL,
  `gross_kg` decimal(10,2) NOT NULL,
  `vol_kg` decimal(10,2) NOT NULL,
  `chargeable_kg` decimal(10,2) NOT NULL,
  `freight` decimal(12,2) NOT NULL,
  `goods` decimal(12,2) NOT NULL,
  `landed_unit` decimal(12,4) NOT NULL,
  `avail` int(11) NOT NULL,
  `ordered` int(11) NOT NULL,
  `production` int(11) NOT NULL,
  `transit` int(11) NOT NULL,
  `cover_days` int(11) NOT NULL,
  `stock_out` date DEFAULT NULL,
  `order_by` date DEFAULT NULL,
  `arrival` date DEFAULT NULL,
  `status` enum('NONE','GREEN','YELLOW','RED') NOT NULL,
  `late_days` int(11) NOT NULL DEFAULT 0,
  `lost_profit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `every_days` int(11) DEFAULT NULL,
  `overlap_orders` smallint(6) DEFAULT NULL,
  `best_mode` varchar(10) DEFAULT NULL,
  `compare_json` text DEFAULT NULL,
  `cash_json` text DEFAULT NULL,
  `assumed_json` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `k_prod` (`fk_id_product`,`run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `imp_batch_effects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `imp_batch_effects` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_batch` int(11) NOT NULL,
  `table_name` varchar(64) NOT NULL,
  `record_id` bigint(20) NOT NULL,
  `effect` enum('INSERT','UPDATE') NOT NULL,
  `before_json` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_batch` (`fk_id_batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `imp_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `imp_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_code` varchar(30) NOT NULL,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_marketplace` int(11) DEFAULT NULL,
  `report_type` varchar(40) NOT NULL,
  `source` enum('UPLOAD','DRIVE','EMAIL','API') NOT NULL DEFAULT 'UPLOAD',
  `file_name` varchar(255) DEFAULT NULL,
  `stored_path` varchar(255) DEFAULT NULL,
  `drive_file_id` varchar(120) DEFAULT NULL,
  `file_sha256` char(64) DEFAULT NULL,
  `period_from` date DEFAULT NULL,
  `period_to` date DEFAULT NULL,
  `rows_total` int(11) NOT NULL DEFAULT 0,
  `rows_new` int(11) NOT NULL DEFAULT 0,
  `rows_duplicate` int(11) NOT NULL DEFAULT 0,
  `rows_changed` int(11) NOT NULL DEFAULT 0,
  `rows_problem` int(11) NOT NULL DEFAULT 0,
  `rows_set_aside` int(11) NOT NULL DEFAULT 0,
  `total_amount` decimal(14,2) DEFAULT NULL,
  `status` enum('UPLOADED','MAPPING','PREVIEW','SAVED','PARTIAL','UNDONE','FAILED') NOT NULL DEFAULT 'UPLOADED',
  `legacy_table` varchar(40) DEFAULT NULL,
  `legacy_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `saved_by` int(11) DEFAULT NULL,
  `saved_at` datetime DEFAULT NULL,
  `undone_by` int(11) DEFAULT NULL,
  `undone_at` datetime DEFAULT NULL,
  `marketplace_fee` decimal(14,2) DEFAULT NULL,
  `va_share_basis` varchar(30) DEFAULT NULL,
  `va_share_pct` decimal(5,2) DEFAULT NULL,
  `va_share_amount` decimal(14,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_code` (`batch_code`),
  UNIQUE KEY `uq_hash` (`fk_id_ent`,`report_type`,`file_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `imp_drive_folders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `imp_drive_folders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `report_type` varchar(40) DEFAULT NULL,
  `folder_url` varchar(500) NOT NULL,
  `folder_id` varchar(120) NOT NULL,
  `last_checked_at` datetime DEFAULT NULL,
  `f_auto_save` tinyint(4) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `imp_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `imp_mappings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `report_type` varchar(40) NOT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `source_column` varchar(190) NOT NULL,
  `decision` enum('MAP','KEEP','SEND','IGNORE') NOT NULL,
  `target` varchar(160) DEFAULT NULL,
  `transform` varchar(120) DEFAULT NULL,
  `confirmed_by` int(11) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_map` (`fk_id_ent`,`report_type`,`fk_id_branch`,`source_column`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `imp_mappings` VALUES
(1,0,'TIKTOK_SETTLEMENT',NULL,'statement date','MAP','settlement.statement_date','date:Y/m/d',NULL,NULL),
(2,0,'TIKTOK_SETTLEMENT',NULL,'statement id','MAP','settlement.statement_id','trim',NULL,NULL),
(3,0,'TIKTOK_SETTLEMENT',NULL,'payment id','MAP','settlement.payout_id','trim',NULL,NULL),
(4,0,'TIKTOK_SETTLEMENT',NULL,'status','KEEP',NULL,NULL,NULL,NULL),
(5,0,'TIKTOK_SETTLEMENT',NULL,'currency','MAP','settlement.currency','upper',NULL,NULL),
(6,0,'TIKTOK_SETTLEMENT',NULL,'type','MAP','settlement.line_type','trim',NULL,NULL),
(7,0,'TIKTOK_SETTLEMENT',NULL,'order/adjustment id','MAP','sale.order_no','trim',NULL,NULL),
(8,0,'TIKTOK_SETTLEMENT',NULL,'sku id','MAP','listing.marketplace_sku_id','trim',NULL,NULL),
(9,0,'TIKTOK_SETTLEMENT',NULL,'quantity','MAP','sale.qty','int',NULL,NULL),
(10,0,'TIKTOK_SETTLEMENT',NULL,'product name','KEEP',NULL,'trim',NULL,NULL),
(11,0,'TIKTOK_SETTLEMENT',NULL,'sku name','KEEP',NULL,'trim',NULL,NULL),
(12,0,'TIKTOK_SETTLEMENT',NULL,'order created date','MAP','sale.order_date','date:Y/m/d',NULL,NULL),
(13,0,'TIKTOK_SETTLEMENT',NULL,'order shipment date','MAP','sale.ship_date','date:Y/m/d',NULL,NULL),
(14,0,'TIKTOK_SETTLEMENT',NULL,'order delivery date','MAP','sale.delivery_date','date:Y/m/d',NULL,NULL),
(15,0,'TIKTOK_SETTLEMENT',NULL,'total settlement amount','MAP','settlement.line_total','money',NULL,NULL),
(16,0,'TIKTOK_SETTLEMENT',NULL,'net sales','MAP','sale.net_sales','money',NULL,NULL),
(17,0,'TIKTOK_SETTLEMENT',NULL,'gross sales','MAP','sale.price_total','money',NULL,NULL),
(18,0,'TIKTOK_SETTLEMENT',NULL,'gross sales refund','MAP','refund.amount','money',NULL,NULL),
(19,0,'TIKTOK_SETTLEMENT',NULL,'seller discount','MAP','sale.seller_discount','money',NULL,NULL),
(20,0,'TIKTOK_SETTLEMENT',NULL,'seller discount refund','MAP','refund.discount_back','money',NULL,NULL),
(21,0,'TIKTOK_SETTLEMENT',NULL,'shipping','MAP','charge.TT_SHIPPING_NET','money',NULL,NULL),
(22,0,'TIKTOK_SETTLEMENT',NULL,'fees','KEEP',NULL,'money',NULL,NULL),
(23,0,'TIKTOK_SETTLEMENT',NULL,'tiktok shop commission fee','MAP','charge.TT_COMMISSION','money',NULL,NULL),
(24,0,'TIKTOK_SETTLEMENT',NULL,'affiliate commission','MAP','charge.TT_AFFILIATE','money',NULL,NULL),
(25,0,'TIKTOK_SETTLEMENT',NULL,'shipping service fee','MAP','charge.TT_SHIPPING_SERVICE','money',NULL,NULL),
(26,0,'TIKTOK_SETTLEMENT',NULL,'smart promotion fee','MAP','charge.TT_PROMOTION','money',NULL,NULL),
(27,0,'TIKTOK_SETTLEMENT',NULL,'managed service plan (per order fee)','MAP','charge.TT_MANAGED_SERVICE','money',NULL,NULL),
(28,0,'TIKTOK_SETTLEMENT',NULL,'vat','MAP','charge.TT_VAT','money',NULL,NULL),
(29,0,'TIKTOK_SETTLEMENT',NULL,'adjustment amount','MAP','charge.TT_ADJUSTMENT','money',NULL,NULL),
(30,0,'TIKTOK_SETTLEMENT',NULL,'adjustment reason','MAP','settlement.adjustment_reason','trim',NULL,NULL),
(31,0,'TIKTOK_SETTLEMENT',NULL,'related order id','MAP','settlement.related_order_no','trim',NULL,NULL),
(32,0,'TIKTOK_SETTLEMENT',NULL,'customer payment','KEEP',NULL,'money',NULL,NULL),
(33,0,'TIKTOK_SETTLEMENT',NULL,'customer refund','KEEP',NULL,'money',NULL,NULL),
(34,0,'TIKTOK_SETTLEMENT',NULL,'chargeable package weight','KEEP',NULL,'int',NULL,NULL),
(35,0,'TIKTOK_SETTLEMENT',NULL,'estimated chargeable package weight','KEEP',NULL,'int',NULL,NULL),
(36,0,'TIKTOK_SETTLEMENT',NULL,'order content source','KEEP',NULL,'trim',NULL,NULL),
(37,0,'TIKTOK_SETTLEMENT',NULL,'delivery option','KEEP',NULL,'trim',NULL,NULL),
(38,0,'TIKTOK_SETTLEMENT',NULL,'collection methods','KEEP',NULL,'trim',NULL,NULL);
DROP TABLE IF EXISTS `imp_report_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `imp_report_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `module` varchar(60) NOT NULL,
  `function_name` varchar(80) NOT NULL,
  `fk_id_marketplace` int(11) DEFAULT NULL,
  `handler` varchar(120) NOT NULL,
  `detect_headers` text DEFAULT NULL,
  `required_fields` text DEFAULT NULL,
  `duplicate_key` varchar(200) DEFAULT NULL,
  `match_with` varchar(120) DEFAULT NULL,
  `period_rule` enum('NONE','MONTH','RANGE','SETTLEMENT') NOT NULL DEFAULT 'MONTH',
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `imp_report_types` VALUES
(1,'EBAY_ORDERS','eBay orders report','Sales','Sales lines',NULL,'Imp_sales_handler',NULL,NULL,'order_no,sku','listing.item_id','MONTH',1),
(2,'EBAY_TRANSACTIONS','eBay transaction report','Sales','Refunds, fees, ad fees, postage',NULL,'Imp_money_report_handler',NULL,NULL,'transaction_id','sale.order_no','MONTH',1),
(3,'EBAY_AUDIT','eBay monthly audit sheet','Audit','Audit lines',NULL,'Imp_ebay_audit_handler',NULL,NULL,'order_no,item_note','sale.order_no','MONTH',1),
(4,'GD_PURCHASES','GD / supplier purchase sheet','Dropshipping','Supplier orders',NULL,'Imp_ds_handler',NULL,NULL,'supplier_order_no','sale.address','MONTH',1),
(5,'DS_SUPPLIER_ORDERS','Dropship supplier orders','Dropshipping','Supplier orders',NULL,'Imp_ds_handler',NULL,NULL,'supplier_order_no','sale.address','MONTH',1),
(6,'AMZ_SETTLEMENT','Amazon settlement (flat file V2)','Finance','Settlement lines',NULL,'Imp_money_report_handler',NULL,NULL,'settlement_id,line_hash','sale.order_no','SETTLEMENT',1),
(7,'AMZ_ORDERS','Amazon all orders','Sales','Sales lines',NULL,'Imp_sales_handler',NULL,NULL,'amazon_order_id,sku','listing.asin','RANGE',1),
(8,'AMZ_FBA_INVENTORY','Amazon FBA inventory','Stock','FBA stock check',NULL,'Imp_fba_stock_handler',NULL,NULL,'sku,snapshot_date','listing.marketplace_sku','NONE',1),
(9,'AMZ_ADS_PRODUCT','Amazon advertised product report','Finance','Ad spend per SKU',NULL,'Imp_ads_handler',NULL,NULL,'date,campaign,sku','listing.marketplace_sku','RANGE',1),
(10,'AMZ_RETURNS','Amazon FBA returns','Sales','Returns',NULL,'Imp_returns_handler',NULL,NULL,'order_id,sku,return_date','sale.order_no','RANGE',1),
(11,'AMZ_REIMBURSE','Amazon reimbursements','Finance','Reimbursements',NULL,'Imp_charges_handler',NULL,NULL,'reimbursement_id','sale.order_no','RANGE',1),
(12,'ONBUY_ORDERS','OnBuy orders','Sales','Sales lines',NULL,'Imp_sales_handler',NULL,NULL,'order_no,sku','listing.item_id','MONTH',1),
(13,'AE_ORDERS','AliExpress orders','Sales','Sales lines',NULL,'Imp_sales_handler',NULL,NULL,'order_no,sku','listing.item_id','MONTH',1),
(14,'ETSY_ORDERS','Etsy orders','Sales','Sales lines',NULL,'Imp_sales_handler',NULL,NULL,'order_no,sku','listing.item_id','MONTH',1),
(15,'TIKTOK_ORDERS','TikTok Shop orders','Sales','Sales lines',NULL,'Imp_sales_handler',NULL,NULL,'order_no,sku','listing.item_id','MONTH',1),
(16,'WISE_BANK','Wise / bank statement','Finance','Bank lines',NULL,'Imp_bank_handler',NULL,NULL,'transaction_id','bank.reference','RANGE',0),
(17,'CARD_STATEMENT','Card statement','Finance','Card lines',NULL,'Imp_bank_handler',NULL,NULL,'date,amount,description','bank.reference','RANGE',1),
(18,'PRODUCTS_BULK','Products bulk file','Products','Products',NULL,'Imp_products_handler',NULL,NULL,'sku','product.sku','NONE',1),
(19,'LISTINGS_BULK','Listings report','Products','Listings',NULL,'Imp_listings_handler',NULL,NULL,'item_id','product.sku','NONE',1),
(20,'SRC_HISTORICAL','Old purchase orders (35 columns)','Sourcing','Old entry',NULL,'Imp_src_historical_handler',NULL,NULL,'po_number,sku','product.sku','NONE',1),
(21,'STOCK_OPENING','Opening stock count','Stock','Opening stock',NULL,'Imp_stock_opening_handler',NULL,NULL,'sku,location','product.sku','NONE',1),
(22,'EBAY_PAYOUTS','Marketplace payouts report (eBay example)','Finance','Payouts',NULL,'Imp_money_report_handler',NULL,NULL,'payout_id','bank.reference','MONTH',1),
(23,'ONBUY_SETTLEMENT','Settlement report (OnBuy example)','Finance','Settlement lines',NULL,'Imp_money_report_handler',NULL,NULL,'settlement_id,line_hash','sale.order_no','SETTLEMENT',1),
(24,'TIKTOK_SETTLEMENT','Settlement report (TikTok example)','Finance','Settlement lines',NULL,'Imp_money_report_handler',NULL,NULL,'settlement_id,line_hash','sale.order_no','SETTLEMENT',1),
(25,'TEMU_SETTLEMENT','Settlement report (Temu example)','Finance','Settlement lines',NULL,'Imp_money_report_handler',NULL,NULL,'settlement_id,line_hash','sale.order_no','SETTLEMENT',1),
(26,'FULFILMENT_ORDERS','Daily orders for dispatch (any marketplace)','Sales','Fulfilment intake lines',NULL,'Imp_fulfilment_handler',NULL,NULL,'order_no,sku','listing.item_id','NONE',1),
(27,'CARRIER_LABEL_PDF','Carrier label PDF','Sales','Labels to sale lines',NULL,'Imp_carrier_label_pdf',NULL,NULL,'tracking_no','sale.order_no','NONE',1),
(28,'INBOUND_CARTONS','Inbound shipment cartons','Stock','Cartons and items',NULL,'Imp_inbound_cartons',NULL,NULL,'shipment_ref,carton_no','listing.marketplace_sku','NONE',1),
(29,'AMAZON_BUSINESS_ORDERS','Supplier order history (Amazon Business example)','Sourcing','Supplier orders',NULL,'Imp_ds_handler',NULL,NULL,'order_id,line_item_id','product.sku','RANGE',1),
(30,'BANK_STATEMENT','Bank / provider statement (read by a bank statement profile)','Finance','Bank lines',NULL,'Imp_bank_handler',NULL,NULL,'transaction_id','bank.reference','RANGE',1);
DROP TABLE IF EXISTS `imp_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `imp_rows` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_batch` int(11) NOT NULL,
  `row_no` int(11) NOT NULL,
  `raw_json` longtext NOT NULL,
  `status` enum('NEW','DUPLICATE','CHANGED','PROBLEM','SET_ASIDE','SAVED','UNDONE') NOT NULL,
  `message` varchar(500) DEFAULT NULL,
  `suggestion` varchar(255) DEFAULT NULL,
  `target_table` varchar(64) DEFAULT NULL,
  `target_id` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_batch` (`fk_id_batch`,`status`),
  KEY `ix_target` (`target_table`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `label_legal_texts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `label_legal_texts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_regulation_profile` int(11) NOT NULL,
  `fk_id_brand` int(11) DEFAULT NULL,
  `fk_id_company_house` int(11) DEFAULT NULL,
  `text_kind` varchar(20) NOT NULL,
  `text_value` text NOT NULL,
  `sort_no` smallint(6) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `ix_llt` (`fk_id_regulation_profile`,`text_kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Legal label texts per regulation profile, brand and company';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `label_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `label_media` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `media_kind` varchar(10) NOT NULL,
  `width_mm` decimal(6,1) NOT NULL,
  `height_mm` decimal(6,1) NOT NULL,
  `gap_mm` decimal(5,1) DEFAULT NULL,
  `liner_width_mm` decimal(6,1) DEFAULT NULL,
  `fk_id_sheet_layout` int(11) DEFAULT NULL,
  `sensor` varchar(10) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_media` (`fk_id_ent`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Label stock / paper loaded in printers';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `label_media` VALUES
(1,0,'SETUP_ROLL_96X136','Carrier thermal roll 96 × 136 mm (setup data)','ROLL',96.0,136.0,3.0,NULL,NULL,'GAP',1,'Free carrier thermal roll; size to be checked with one test print.'),
(2,0,'SETUP_ROLL_100X150','Thermal labels 100 × 150 mm (setup data)','ROLL',100.0,150.0,3.0,NULL,NULL,'GAP',1,NULL),
(3,0,'SETUP_A4_PLAIN','Plain A4 paper (setup data)','SHEET',210.0,297.0,NULL,NULL,NULL,NULL,1,NULL);
DROP TABLE IF EXISTS `label_print_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `label_print_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_sale` bigint(20) DEFAULT NULL,
  `fk_id_fba_carton` int(11) DEFAULT NULL,
  `fk_id_label_template` int(11) NOT NULL,
  `fk_id_printer_profile` int(11) NOT NULL,
  `copy_no` smallint(6) NOT NULL,
  `reprint_reason` varchar(30) DEFAULT NULL,
  `label_file` varchar(255) DEFAULT NULL,
  `printed_by` int(11) NOT NULL,
  `printed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `entity_type` varchar(20) DEFAULT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `fk_id_print_job` int(11) DEFAULT NULL,
  `fk_id_parcel` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_lpl_sale` (`fk_id_sale`),
  KEY `ix_lpl_carton` (`fk_id_fba_carton`),
  KEY `ix_lpl_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Every printed label copy';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `label_regulation_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `label_regulation_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `conformity_mark_text` varchar(40) DEFAULT NULL,
  `f_needs_origin` tinyint(4) NOT NULL DEFAULT 1,
  `f_needs_responsible_person` tinyint(4) NOT NULL DEFAULT 0,
  `f_needs_manufacturer` tinyint(4) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lrp` (`fk_id_ent`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Label regulation profile per destination market (M50, R-D-92)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `label_sheet_layouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `label_sheet_layouts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `paper_kind` varchar(8) NOT NULL,
  `cols` tinyint(4) NOT NULL DEFAULT 1,
  `rows_per_sheet` tinyint(4) NOT NULL DEFAULT 1,
  `label_w_mm` decimal(6,1) NOT NULL,
  `label_h_mm` decimal(6,1) NOT NULL,
  `margin_top_mm` decimal(5,1) NOT NULL DEFAULT 0.0,
  `margin_left_mm` decimal(5,1) NOT NULL DEFAULT 0.0,
  `gap_x_mm` decimal(5,1) NOT NULL DEFAULT 0.0,
  `gap_y_mm` decimal(5,1) NOT NULL DEFAULT 0.0,
  `f_system` tinyint(4) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lsl` (`fk_id_ent`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Label sheet layouts: single roll label, A4 N-up, custom';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `label_sheet_layouts` VALUES
(1,0,'ROLL_100X150','Single roll label 100 × 150 mm','ROLL',1,1,100.0,150.0,0.0,0.0,0.0,0.0,1,1),
(2,0,'ROLL_96X136','Single roll label 96 × 136 mm','ROLL',1,1,96.0,136.0,0.0,0.0,0.0,0.0,1,1),
(3,0,'A4_4UP','A4 4-up 99.1 × 139 mm','A4',2,2,99.1,139.0,9.0,4.6,2.5,0.0,1,1),
(4,0,'A4_8UP','A4 8-up 99.1 × 67.7 mm','A4',2,4,99.1,67.7,13.0,4.6,2.5,0.0,1,1),
(5,0,'A4_21UP','A4 21-up 63.5 × 38.1 mm','A4',3,7,63.5,38.1,15.1,7.2,2.5,0.0,1,1);
DROP TABLE IF EXISTS `label_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `label_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `label_kind` varchar(20) NOT NULL,
  `label_format` varchar(20) NOT NULL,
  `fk_id_printer_profile` int(11) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `fk_id_mp_profile` int(11) DEFAULT NULL,
  `layout` text NOT NULL,
  `copies_default` tinyint(4) NOT NULL DEFAULT 1,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `fk_id_sheet_layout` int(11) DEFAULT NULL,
  `fk_id_regulation_profile` int(11) DEFAULT NULL,
  `width_mm` decimal(6,1) DEFAULT NULL,
  `height_mm` decimal(6,1) DEFAULT NULL,
  `orientation` varchar(10) NOT NULL DEFAULT 'PORTRAIT',
  `template_source` varchar(12) NOT NULL DEFAULT 'SYSTEM',
  `scale_rule` varchar(8) NOT NULL DEFAULT 'ACTUAL',
  `crop_rule` varchar(20) DEFAULT NULL,
  `fk_id_label_media` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_label_tpl` (`fk_id_ent`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Label templates';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `label_templates` VALUES
(1,0,'EX_LBL_100X150','Shipping label 100 × 150 mm from carrier PDF (example)','SHIPPING','100X150_MM',1,NULL,NULL,'{\"crop\":\"FULL_PAGE\"}',1,1,NULL,NULL,100.0,150.0,'PORTRAIT','CARRIER_PDF','ACTUAL','FULL_PAGE',NULL),
(2,0,'EX_LBL_A4_LOCATION','Location labels on A4 sheet (example)','LOCATION','A4',2,NULL,NULL,'{\"fields\":[\"barcode_value\",\"location_code\"]}',1,1,NULL,NULL,70.0,37.0,'PORTRAIT','SYSTEM','ACTUAL',NULL,NULL);
DROP TABLE IF EXISTS `marketplace_5_matching_rule_columns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketplace_5_matching_rule_columns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_marketplace_id` int(11) NOT NULL,
  `match_field` varchar(50) NOT NULL COMMENT 'ship_name | ship_address | ship_city | ship_postcode | ship_phone (drp_items columns)',
  `csv_columns` varchar(1000) NOT NULL DEFAULT '' COMMENT 'Comma separated CSV header names',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mp_match_field` (`fk_marketplace_id`,`match_field`),
  KEY `idx_mmc_ent` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `marketplace_ds_columns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketplace_ds_columns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_marketplace_id` int(11) NOT NULL,
  `ds_field` varchar(50) NOT NULL COMMENT 'order_no, order_date, product_title, sku, variant, qty, unit_price, shipping_cost, total_paid, currency, tracking_no, buyer_name, buyer_address, buyer_postcode, buyer_country, buyer_phone',
  `csv_columns` varchar(1000) NOT NULL DEFAULT '' COMMENT 'Comma separated CSV header names',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_marketplace_ds_field` (`fk_marketplace_id`,`ds_field`),
  KEY `idx_mdc_ent` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `marketplace_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketplace_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_marketplace_id` int(11) NOT NULL,
  `question` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `marketplace_sale_columns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketplace_sale_columns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_marketplace_id` int(11) NOT NULL,
  `sale_field` varchar(50) NOT NULL COMMENT 'order_no, order_date, cust_name, cust_contacts, sku, product_info, qty, sale_rate, total_amount',
  `csv_columns` varchar(1000) NOT NULL DEFAULT '' COMMENT 'Comma separated CSV header names',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_marketplace_sale_field` (`fk_marketplace_id`,`sale_field`),
  KEY `idx_msc_ent` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `marketplace_sale_custom_columns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketplace_sale_custom_columns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_marketplace_id` int(11) NOT NULL,
  `col_label` varchar(60) NOT NULL COMMENT 'column title in the Sales listing',
  `csv_columns` varchar(500) NOT NULL DEFAULT '' COMMENT 'CSV header name(s), comma separated',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mscc_marketplace` (`fk_marketplace_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `mp_profile_fee_buckets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mp_profile_fee_buckets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_mp_profile` int(11) NOT NULL,
  `bucket_code` varchar(30) NOT NULL,
  `label` varchar(80) NOT NULL,
  `source_rule` varchar(300) NOT NULL,
  `charge_kind` varchar(30) NOT NULL,
  `sort_no` smallint(6) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `sign_rule` varchar(8) NOT NULL DEFAULT 'COST',
  `f_in_selling_costs` tinyint(4) NOT NULL DEFAULT 1,
  `hub_label` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mpfb` (`fk_id_mp_profile`,`bucket_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Fee buckets of a marketplace profile (R-A_SALES-83)';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `mp_profile_fee_buckets` VALUES
(1,1,'INSERTION','Insertion fees','Other fee, Description \"Insertion fee\"','INSERTION',10,1,'COST',1,NULL),
(2,1,'FVF','Final value fee – standard','Order rows: final value fee fixed + variable','FVF',20,1,'COST',1,NULL),
(3,1,'FVF_INAD','Very high \"item not as described\" fee','Order rows: column of that name','FVF_INAD',30,1,'COST',1,NULL),
(4,1,'REGULATORY','Regulatory operating fee','Order rows','REGULATORY',40,1,'COST',1,NULL),
(5,1,'INTERNATIONAL','International fee','Order rows','INTERNATIONAL',50,1,'COST',1,NULL),
(6,1,'AD_FEE','Ad fees (general)','Other fee \"Promoted Listings – General fee\", negative lines only','AD_FEE',60,1,'COST',1,NULL),
(7,1,'BELOW_STD','Below standard performance fee','Order rows','BELOW_STD',70,1,'COST',1,NULL),
(8,1,'FEE_CREDIT','Transaction fee credits','Fee columns on Refund rows + positive Promoted Listings lines (ad credits). Claim rows are NOT included (bucket CLAIM_FEE_CREDIT)','FEE_CREDIT',80,1,'CREDIT',1,NULL),
(9,2,'SETTLEMENT_FEE','Platform fees','Settlement report fee columns','SETTLEMENT_FEE',10,0,'COST',1,NULL),
(10,2,'REFUND','Refunds','Settlement report refund lines','REFUND',20,0,'COST',0,NULL),
(11,1,'POSTAGE_LABEL','Postage labels','Rows of Type \"Shipping label\" (labels bought through the marketplace), net of label refunds / voids','POSTAGE_LABEL',75,1,'COST',1,NULL),
(12,1,'CLAIM_FEE_CREDIT','Claim fee credits (outside selling costs)','Fee credit on Claim rows – shown with the claim result, not in selling costs','CLAIM_FEE_CREDIT',90,1,'CREDIT',0,NULL),
(13,2,'TT_COMMISSION','Commission','Order details: TikTok Shop commission fee','TT_COMMISSION',10,1,'AS_FILE',1,'TikTok Shop commission fee'),
(14,2,'TT_AFFILIATE','Affiliate / creator commission','Affiliate Commission + Affiliate partner commission + Affiliate Shop Ads commission + Affiliate Partner shop ads commission + Affiliate commission deposit + Affiliate commission refund','TT_AFFILIATE',20,1,'AS_FILE',1,'Affiliate Commission'),
(15,2,'TT_SHIPPING_SERVICE','Shipping service fee','Order details: Shipping service fee','TT_SHIPPING_SERVICE',30,1,'AS_FILE',1,'Shipping service fee'),
(16,2,'TT_PROMOTION','Promotion and ad fees','Smart Promotion fee + Campaign resource fee + Campaign service fee + Co-funded promotion (seller-funded) + GMV Max ad fee + Auto-post shoppable video commission fee','TT_PROMOTION',40,1,'AS_FILE',1,'Smart Promotion fee'),
(17,2,'TT_MANAGED_SERVICE','Managed service plan','Managed service plan (Per order fee)','TT_MANAGED_SERVICE',50,1,'AS_FILE',1,'Managed service plan (Per order fee)'),
(18,2,'TT_VAT','VAT on fees','Order details: VAT','TT_VAT',60,1,'AS_FILE',1,'VAT'),
(19,2,'TT_SHIPPING_NET','Shipping (net)','Shipping group: Customer-paid shipping fee (+ refund) + Shipping fee subsidy + TikTok Shop shipping fee + FBT shipping fee + shipping incentive (+ refund) + Return shipping fee + Shipping fee discount + Fee for FBT free shipping + Return shipping label fee + Return shipping fee reimbursement','TT_SHIPPING_NET',70,1,'AS_FILE',1,'Shipping'),
(20,2,'TT_CHARGEBACK','Chargebacks','Type/Adjustment reason = Chargeback','TT_CHARGEBACK',80,1,'AS_FILE',1,'Chargeback'),
(21,2,'TT_FULFIL_STORAGE','Fulfilment warehouse service fee','Type/Adjustment reason = FBT warehouse service fee (fulfilment cost of the 3PL / marketplace programme)','TT_FULFIL_STORAGE',90,1,'AS_FILE',1,'FBT warehouse service fee'),
(22,2,'TT_ADJUSTMENT','Other adjustments','Adjustment rows: logistics adjustment / reimbursement / deduction, TikTok Shop reimbursement, violation fee, fees adjustment, promotion adjustment, settlement account adjustment, seller mission reward, tax adjustment, platform semi-managed fee, other','TT_ADJUSTMENT',100,1,'AS_FILE',1,'Adjustments');
DROP TABLE IF EXISTS `mp_profile_health_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mp_profile_health_metrics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_mp_profile` int(11) NOT NULL,
  `metric_code` varchar(30) NOT NULL,
  `label` varchar(80) NOT NULL,
  `unit` varchar(10) NOT NULL,
  `direction` varchar(10) NOT NULL,
  `threshold_amber` decimal(12,4) DEFAULT NULL,
  `threshold_red` decimal(12,4) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mphm` (`fk_id_mp_profile`,`metric_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Account health metrics of a marketplace profile';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `mp_profile_refund_kinds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mp_profile_refund_kinds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_mp_profile` int(11) NOT NULL,
  `kind_code` varchar(20) NOT NULL,
  `label` varchar(80) NOT NULL,
  `pct_from` decimal(5,2) DEFAULT NULL,
  `pct_to` decimal(5,2) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mprk` (`fk_id_mp_profile`,`kind_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Refund kinds of a marketplace profile (IC-12)';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `mp_profile_refund_kinds` VALUES
(1,1,'FULL','Full',NULL,NULL,1),
(2,1,'HALF','Half',49.00,51.00,1),
(3,1,'PARTIAL','Partial',NULL,NULL,1),
(4,1,'OUTSIDE','Outside the order',NULL,NULL,1),
(5,2,'FULL','Full',NULL,NULL,1),
(6,2,'HALF','Half',49.00,51.00,1),
(7,2,'PARTIAL','Partial',NULL,NULL,1),
(8,2,'OUTSIDE','Outside the order',NULL,NULL,1);
DROP TABLE IF EXISTS `mp_profile_report_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mp_profile_report_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_mp_profile` int(11) NOT NULL,
  `report_type` varchar(40) NOT NULL,
  `report_role` varchar(20) NOT NULL,
  `f_required_for_close` tinyint(4) NOT NULL DEFAULT 1,
  `detect_headers` varchar(500) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mprt` (`fk_id_mp_profile`,`report_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Report types of a marketplace profile';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `mp_profile_report_types` VALUES
(1,1,'EBAY_ORDERS','ORDERS',1,NULL,1),
(2,1,'EBAY_TRANSACTIONS','MONEY',1,NULL,1),
(3,1,'EBAY_PAYOUTS','PAYOUTS',1,NULL,1),
(4,2,'FULFILMENT_ORDERS','FULFILMENT',1,NULL,1),
(5,2,'TIKTOK_SETTLEMENT','MONEY',1,NULL,1);
DROP TABLE IF EXISTS `mp_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mp_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `fk_id_marketplace` int(11) DEFAULT NULL,
  `timezone` varchar(40) NOT NULL,
  `f_daily_only` tinyint(4) NOT NULL DEFAULT 0,
  `fulfilment_programme` varchar(20) NOT NULL DEFAULT 'SELF',
  `money_report_type` varchar(40) NOT NULL,
  `payout_rule` varchar(30) NOT NULL,
  `payout_cycle` varchar(20) DEFAULT NULL,
  `money_lag_days` smallint(6) DEFAULT NULL,
  `month_total_rule` varchar(30) DEFAULT NULL,
  `va_share_basis` varchar(30) NOT NULL DEFAULT 'PROFIT_AFTER_FEE',
  `required_for_close` varchar(500) NOT NULL,
  `overdue_days` smallint(6) NOT NULL,
  `buy_note_field` varchar(60) DEFAULT NULL,
  `tracking_upload_layout` varchar(40) DEFAULT NULL,
  `fk_id_api_connection` int(11) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `handling_days` smallint(6) DEFAULT NULL,
  `dispatch_cutoff_time` time DEFAULT NULL,
  `stock_sync_mode` varchar(6) NOT NULL DEFAULT 'OFF',
  `stock_buffer_units` int(11) NOT NULL DEFAULT 0,
  `oos_action` varchar(6) NOT NULL DEFAULT 'ZERO',
  `tracking_upload_when` varchar(12) NOT NULL DEFAULT 'ON_HANDOVER',
  `f_service_from_order` tinyint(4) NOT NULL DEFAULT 0,
  `api_priority` smallint(6) DEFAULT NULL,
  `orders_cadence` varchar(10) NOT NULL DEFAULT 'MONTHLY',
  `money_cadence` varchar(12) DEFAULT NULL,
  `payout_target` varchar(16) NOT NULL DEFAULT 'BANK',
  `base_report_currency` char(3) DEFAULT NULL,
  `hub_totals_available` tinyint(4) NOT NULL DEFAULT 1,
  `return_window_days` smallint(6) DEFAULT NULL,
  `seller_portal_url` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mp_profile_code` (`fk_id_ent`,`code`),
  KEY `ix_mp_profile_mp` (`fk_id_marketplace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Marketplace profile: reports, fees, refunds, payout, health, VA basis (IC-0)';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `mp_profiles` VALUES
(1,0,'EBAY_EXAMPLE','eBay (example profile)',NULL,'UTC',0,'SELF','EBAY_TRANSACTIONS','PER_PAYOUT_LINE','Daily',NULL,'MASTER_ROW','PROFIT_AFTER_FEE','FILES_PRESENT,ORDERS_VS_TXN,HUB_TOTALS,PAYOUT_VS_BANK,BANK_ALL_CATEGORISED,PURCHASE_VS_SUPPLIER,SALES_CONFIRMED,COST_PRESENT,FX_PRESENT,EXPENSES,STOCK_ROLL,SHARES_LE_100,SPLIT_RULES,OPENING_LOCKED,PREVIOUS_LOCKED,ONE_PROFIT_ROUTE',10,NULL,NULL,NULL,1,NULL,NULL,'2026-11-12 04:31:47',NULL,NULL,NULL,NULL,'OFF',0,'ZERO','ON_HANDOVER',0,NULL,'MONTHLY','ROLLING','BANK',NULL,1,NULL,NULL),
(2,0,'TIKTOK_EXAMPLE','TikTok Shop (example profile)',NULL,'UTC',1,'SELF','TIKTOK_SETTLEMENT','PER_PAYOUT_LINE',NULL,NULL,'SUM_LINES','PROFIT_AFTER_FEE','FILES_PRESENT,SETTLEMENT_COMPLETE,PAYOUT_VS_BANK,BANK_ALL_CATEGORISED,PURCHASE_VS_SUPPLIER,SALES_CONFIRMED,COST_PRESENT,FX_PRESENT,EXPENSES,STOCK_ROLL,SHARES_LE_100,SPLIT_RULES,OPENING_LOCKED,PREVIOUS_LOCKED,ONE_PROFIT_ROUTE',10,NULL,NULL,NULL,1,NULL,NULL,'2026-11-12 04:31:47',NULL,NULL,NULL,NULL,'OFF',0,'ZERO','ON_HANDOVER',0,NULL,'DAILY','PER_PAYOUT','BANK',NULL,1,NULL,NULL);
DROP TABLE IF EXISTS `payment_provider_fee_buckets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_provider_fee_buckets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_provider_profile` int(11) NOT NULL,
  `bucket_code` varchar(30) NOT NULL,
  `label` varchar(120) NOT NULL,
  `source_rule` varchar(200) NOT NULL,
  `charge_kind` varchar(30) NOT NULL,
  `sign_rule` varchar(12) NOT NULL DEFAULT 'AS_IS',
  `sort_no` smallint(6) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ppfb` (`fk_id_provider_profile`,`bucket_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Fee buckets of a payment-provider profile (same shape as mp_profile_fee_buckets)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `payment_provider_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_provider_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `provider_kind` varchar(20) NOT NULL,
  `fk_id_bank` int(11) DEFAULT NULL,
  `fk_id_statement_profile` int(11) DEFAULT NULL,
  `statement_report_type` varchar(40) DEFAULT NULL,
  `currencies_held` varchar(200) DEFAULT NULL,
  `payout_rule` varchar(30) NOT NULL,
  `fk_id_payout_bank_account` int(11) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ppp_code` (`fk_id_ent`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Payment-provider profile (IC-0.5) – no provider named in code';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `payment_provider_profiles` VALUES
(1,0,'EX_WALLET','Wallet provider (example)','WALLET',NULL,1,NULL,NULL,'PER_PAYOUT_LINE',NULL,1,'Example row for tests (IC-0.10)',NULL,'2026-11-12 04:31:48',NULL,NULL),
(2,0,'EX_FX_TRANSFER','Money-transfer provider (example)','FX_TRANSFER',NULL,2,NULL,NULL,'DAILY_SWEEP',NULL,1,'Example row for tests (IC-0.10)',NULL,'2026-11-12 04:31:48',NULL,NULL);
DROP TABLE IF EXISTS `paypal_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `paypal_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `paypal_id` int(11) DEFAULT NULL,
  `question` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `print_adapters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `print_adapters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `output_format` varchar(12) NOT NULL,
  `needs_local_agent` tinyint(4) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='How the tool sends a job to a printer (one handler per row)';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `print_adapters` VALUES
(1,'DRIVER_PDF','PDF at exact size through the computer\'s printer driver','PDF',0,1,'Default. Works with any printer that has a driver (thermal, laser, inkjet, future models).'),
(2,'PRINT_AGENT','PDF or raw sent silently by the print helper on the packing PC','PDF',1,1,'One-click printing without the browser dialog; same output as DRIVER_PDF or RAW.'),
(3,'RAW_TSPL','Raw TSPL commands (many thermal printers)','TSPL',1,1,NULL),
(4,'RAW_ZPL','Raw ZPL commands (Zebra-compatible printers)','ZPL',1,1,NULL),
(5,'RAW_EPL','Raw EPL commands','EPL',1,1,NULL),
(6,'RAW_OPL','Raw OPL commands','OPL',1,1,NULL),
(7,'IPP','Network printing over IPP / IPP Everywhere / AirPrint','PDF',0,1,'Printers on the network that accept PDF directly.'),
(8,'CLOUD','Cloud print service (profile of a print service provider)','PDF',0,1,'Provider settings in a profile; no provider fixed.');
DROP TABLE IF EXISTS `print_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `print_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `job_code` varchar(30) NOT NULL,
  `entity_type` varchar(20) NOT NULL,
  `entity_ids` text NOT NULL,
  `fk_id_label_template` int(11) NOT NULL,
  `fk_id_printer_profile` int(11) NOT NULL,
  `copies` smallint(6) NOT NULL DEFAULT 1,
  `print_trigger` varchar(16) NOT NULL DEFAULT 'MANUAL',
  `status` varchar(10) NOT NULL DEFAULT 'QUEUED',
  `error_text` varchar(255) DEFAULT NULL,
  `file_ref` varchar(255) DEFAULT NULL,
  `workstation` varchar(80) DEFAULT NULL,
  `requested_by` int(11) NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `printed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pj` (`job_code`),
  KEY `ix_pj_status` (`fk_id_ent`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Print queue (R-H-56, 18_Labels §11)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `print_routes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `print_routes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `job_kind` varchar(20) NOT NULL,
  `scope` varchar(12) NOT NULL DEFAULT 'COMPANY',
  `scope_value` varchar(80) DEFAULT NULL,
  `fk_id_printer_profile` int(11) NOT NULL,
  `fk_id_backup_printer` int(11) DEFAULT NULL,
  `fk_id_label_media` int(11) DEFAULT NULL,
  `copies` tinyint(4) NOT NULL DEFAULT 1,
  `tray` varchar(30) DEFAULT NULL,
  `f_duplex` tinyint(4) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_route` (`fk_id_ent`,`job_kind`,`scope`,`scope_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Job kind → printer, with backup';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `printer_calibrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `printer_calibrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_printer_profile` int(11) NOT NULL,
  `fk_id_label_media` int(11) NOT NULL,
  `offset_x_mm` decimal(5,2) NOT NULL DEFAULT 0.00,
  `offset_y_mm` decimal(5,2) NOT NULL DEFAULT 0.00,
  `scale_pct` decimal(5,2) NOT NULL DEFAULT 100.00,
  `rotate_deg` smallint(6) NOT NULL DEFAULT 0,
  `darkness` tinyint(4) DEFAULT NULL,
  `speed_mm_s` smallint(6) DEFAULT NULL,
  `test_print_ok` tinyint(4) NOT NULL DEFAULT 0,
  `calibrated_by` int(11) DEFAULT NULL,
  `calibrated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cal` (`fk_id_printer_profile`,`fk_id_label_media`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Fine tuning so output matches the template exactly';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `printer_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `printer_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `printer_kind` varchar(12) NOT NULL,
  `model_text` varchar(120) DEFAULT NULL,
  `connection` varchar(12) NOT NULL,
  `paper_format` varchar(20) NOT NULL,
  `dpi` smallint(6) DEFAULT NULL,
  `fk_id_location` int(11) DEFAULT NULL,
  `f_default` tinyint(4) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `max_print_width_mm` decimal(6,1) DEFAULT NULL,
  `command_language` varchar(20) DEFAULT NULL,
  `print_path` varchar(12) NOT NULL DEFAULT 'DRIVER_PDF',
  `f_duplex` tinyint(4) NOT NULL DEFAULT 0,
  `fk_id_default_label_template` int(11) DEFAULT NULL,
  `os_printer_name` varchar(120) DEFAULT NULL,
  `brand_text` varchar(60) DEFAULT NULL,
  `fk_id_adapter` int(11) DEFAULT NULL,
  `colour_mode` varchar(8) NOT NULL DEFAULT 'MONO',
  `dpi_options` varchar(40) DEFAULT NULL,
  `min_media_width_mm` decimal(6,1) DEFAULT NULL,
  `max_media_width_mm` decimal(6,1) DEFAULT NULL,
  `max_print_length_mm` decimal(7,1) DEFAULT NULL,
  `paper_sizes` varchar(120) DEFAULT NULL,
  `trays` varchar(120) DEFAULT NULL,
  `command_languages` varchar(80) DEFAULT NULL,
  `connection_list` varchar(80) DEFAULT NULL,
  `network_address` varchar(120) DEFAULT NULL,
  `speed_note` varchar(60) DEFAULT NULL,
  `printer_status` varchar(10) NOT NULL DEFAULT 'ACTIVE',
  `fk_id_replaced_by` int(11) DEFAULT NULL,
  `last_test_print_at` datetime DEFAULT NULL,
  `last_test_print_ok` tinyint(4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_printer` (`fk_id_ent`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Printers (examples only in the docs; no printer is hard-coded)';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `printer_profiles` VALUES
(1,0,'EX_PR_THERMAL','Thermal roll printer (example)','THERMAL','4-inch thermal label printer','USB','100X150_MM',203,NULL,0,1,NULL,108.0,'TSPL','DRIVER_PDF',0,1,NULL,NULL,NULL,'MONO',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'ACTIVE',NULL,NULL,NULL),
(2,0,'EX_PR_A4','A4 laser printer (example)','LASER','A4 mono laser','NETWORK','A4',1200,NULL,0,1,NULL,210.0,'PCL','DRIVER_PDF',1,NULL,NULL,NULL,NULL,'MONO',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'ACTIVE',NULL,NULL,NULL),
(3,0,'SETUP_THERMAL_1','Thermal label printer 1 (setup data)','THERMAL','ITPP941B','USB','100X150_MM',203,NULL,0,1,'From the printer sticker, 11 Nov 2026. Shipping, return, product, carton and bin labels on rolls or fanfold.',108.0,'TSPL','DRIVER_PDF',0,NULL,NULL,'MUNBYN',NULL,'MONO','203',40.0,110.0,300.0,NULL,NULL,'TSPL','USB,BLUETOOTH',NULL,NULL,'ACTIVE',NULL,NULL,NULL),
(4,0,'SETUP_A4_1','A4 office printer 1 (setup data)','LASER','ECOSYS P3145dn','NETWORK','A4',1200,NULL,0,1,'From the printer front, 11 Nov 2026. A4 documents and A4 label sheets.',210.0,'PCL','DRIVER_PDF',1,NULL,NULL,'KYOCERA',NULL,'MONO','600,1200',NULL,NULL,NULL,'A4,A5,LETTER',NULL,'PCL6,KPDL','NETWORK,USB',NULL,NULL,'ACTIVE',NULL,NULL,NULL);
DROP TABLE IF EXISTS `product_artwork_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_artwork_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_product` int(11) NOT NULL,
  `fk_id_packaging` int(11) DEFAULT NULL,
  `artwork_kind` varchar(12) NOT NULL,
  `fk_id_document` int(11) NOT NULL,
  `version_no` varchar(20) NOT NULL,
  `artwork_status` varchar(12) NOT NULL DEFAULT 'DRAFT',
  `f_printer_ready` tinyint(4) NOT NULL DEFAULT 0,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_art_prod` (`fk_id_product`,`artwork_kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Artwork files of a product with version and approval (R-D-90)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `product_certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_product` int(11) NOT NULL,
  `fk_id_cert_type` int(11) NOT NULL,
  `fk_id_regulation_profile` int(11) DEFAULT NULL,
  `cert_no` varchar(80) DEFAULT NULL,
  `issuer_text` varchar(150) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `fk_id_document` int(11) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_cert_prod` (`fk_id_product`),
  KEY `ix_cert_exp` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Certificate vault per product (R-D-91)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `product_packaging`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_packaging` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_product` int(11) NOT NULL,
  `pack_qty` smallint(6) NOT NULL DEFAULT 1,
  `box_type` varchar(12) NOT NULL,
  `length_mm` decimal(8,1) DEFAULT NULL,
  `width_mm` decimal(8,1) DEFAULT NULL,
  `height_mm` decimal(8,1) DEFAULT NULL,
  `packed_weight_g` decimal(10,1) DEFAULT NULL,
  `units_inside` smallint(6) DEFAULT NULL,
  `fk_id_material` int(11) DEFAULT NULL,
  `colour_text` varchar(80) DEFAULT NULL,
  `fk_id_print_supplier` int(11) DEFAULT NULL,
  `artwork_version` varchar(20) DEFAULT NULL,
  `artwork_date` date DEFAULT NULL,
  `fk_id_dieline_doc` int(11) DEFAULT NULL,
  `approval_status` varchar(12) NOT NULL DEFAULT 'DRAFT',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `source_note` varchar(255) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pack` (`fk_id_product`,`pack_qty`,`box_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Packaging master per SKU and pack (R-D-89)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `pur_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pur_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_audit_main` int(11) NOT NULL,
  `fk_id_vendor` int(11) NOT NULL,
  `order_no` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `d_order` date DEFAULT NULL,
  `pur_amount` decimal(10,2) DEFAULT NULL,
  `cust_name` varchar(200) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `cust_address` varchar(1000) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_id_audit_main_in_pur_audit` (`fk_id_audit_main`),
  KEY `idx_id_vendor_in_pur_audit` (`fk_id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `pur_audit_gd`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pur_audit_gd` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_audit_main` int(11) NOT NULL,
  `fk_id_vendor` int(11) NOT NULL,
  `order_no` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `d_order` date DEFAULT NULL,
  `sku_gd` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `e_hold_buy` varchar(5) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `g_pur_price` decimal(11,2) NOT NULL,
  `product_title` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `qty` int(11) NOT NULL,
  `price` decimal(11,2) NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `trading_id` varchar(25) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `tracking_number` varchar(25) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `payer_email` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `address1` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `address2` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `address3` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `city` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `county` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `buyer_country` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `recipient` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `buyer_post_code` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `buyer_email` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `phone` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_audit_main_in_gd_audit` (`fk_id_audit_main`),
  KEY `fk_id_vendor_in_gd_audit` (`fk_id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `reference_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reference_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_reference_id` int(11) NOT NULL,
  `reference_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fk_id_ent` int(11) DEFAULT NULL,
  `item_code` varchar(40) DEFAULT NULL,
  `sort_no` int(11) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `f_pending` tinyint(4) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `legacy_table` varchar(40) DEFAULT NULL,
  `legacy_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_reference_id` (`fk_reference_id`),
  KEY `ix_ref` (`fk_reference_id`,`f_active`,`sort_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `reference_items` VALUES
(1,18,'Urgent','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'URGENT',1,1,0,NULL,NULL,NULL),
(2,18,'High','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'HIGH',2,1,0,NULL,NULL,NULL),
(3,18,'Normal','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'NORMAL',3,1,0,NULL,NULL,NULL),
(4,18,'Low','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'LOW',4,1,0,NULL,NULL,NULL),
(5,20,'Weekly','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'WEEKLY',1,1,0,NULL,NULL,NULL),
(6,20,'Monthly','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'MONTHLY',2,1,0,NULL,NULL,NULL),
(7,20,'Quarterly','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'QUARTERLY',3,1,0,NULL,NULL,NULL),
(8,20,'Yearly','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'YEARLY',4,1,0,NULL,NULL,NULL),
(9,20,'One-off','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'ONEOFF',5,1,0,NULL,NULL,NULL),
(10,22,'Not as described','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'NAD',1,1,0,NULL,NULL,NULL),
(11,22,'Damaged in transit','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DAMAGED',2,1,0,NULL,NULL,NULL),
(12,22,'Changed mind','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'MIND',3,1,0,NULL,NULL,NULL),
(13,22,'Wrong item sent','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'WRONG',4,1,0,NULL,NULL,NULL),
(14,22,'Faulty','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'FAULTY',5,1,0,NULL,NULL,NULL),
(15,23,'Sellable','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SELLABLE',1,1,0,NULL,NULL,NULL),
(16,23,'Damaged packaging','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PKG',2,1,0,NULL,NULL,NULL),
(17,23,'Damaged','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DAMAGED',3,1,0,NULL,NULL,NULL),
(18,23,'Missing parts','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'MISSING',4,1,0,NULL,NULL,NULL),
(19,24,'Stock count correction','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'COUNT',1,1,0,NULL,NULL,NULL),
(20,24,'Damaged','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DAMAGED',2,1,0,NULL,NULL,NULL),
(21,24,'Lost','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'LOST',3,1,0,NULL,NULL,NULL),
(22,24,'Sample used','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SAMPLE',4,1,0,NULL,NULL,NULL),
(23,24,'Supplier short-shipped','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SHORT',5,1,0,NULL,NULL,NULL),
(24,19,'Supplier invoice','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SUP_INV',1,1,0,NULL,NULL,NULL),
(25,19,'Proforma invoice','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PI',2,1,0,NULL,NULL,NULL),
(26,19,'Payment proof','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PAY',3,1,0,NULL,NULL,NULL),
(27,19,'Packing list','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PACK',4,1,0,NULL,NULL,NULL),
(28,19,'Bill of lading','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'BL',5,1,0,NULL,NULL,NULL),
(29,19,'Customs entry','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'CUSTOMS',6,1,0,NULL,NULL,NULL),
(30,19,'Test report','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'TEST',7,1,0,NULL,NULL,NULL),
(31,19,'Contract','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'CONTRACT',8,1,0,NULL,NULL,NULL),
(32,19,'ID document','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'ID',9,1,0,NULL,NULL,NULL),
(33,26,'Where is my order','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'WISMO',1,1,0,NULL,NULL,NULL),
(34,26,'Damaged item','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DAMAGED',2,1,0,NULL,NULL,NULL),
(35,26,'Return request','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'RETURN',3,1,0,NULL,NULL,NULL),
(36,26,'Refund request','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'REFUND',4,1,0,NULL,NULL,NULL),
(37,26,'Product question','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'QUESTION',5,1,0,NULL,NULL,NULL),
(38,26,'Negative feedback','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'FEEDBACK',6,1,0,NULL,NULL,NULL),
(39,25,'VPN','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'VPN',1,1,0,NULL,NULL,NULL),
(40,25,'VPS / RDP','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'VPS',2,1,0,NULL,NULL,NULL),
(41,25,'Phone number','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PHONE',3,1,0,NULL,NULL,NULL),
(42,25,'Tool subscription','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'TOOL',4,1,0,NULL,NULL,NULL),
(43,25,'Card','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'CARD',5,1,0,NULL,NULL,NULL),
(44,25,'Email account','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'EMAIL',6,1,0,NULL,NULL,NULL),
(45,25,'Domain / hosting','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DOMAIN',7,1,0,NULL,NULL,NULL),
(46,21,'Advertising','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'ADS',1,1,0,NULL,NULL,NULL),
(47,21,'Postage','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'POSTAGE',2,1,0,NULL,NULL,NULL),
(48,21,'Packaging','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PACKAGING',3,1,0,NULL,NULL,NULL),
(49,21,'Software','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SOFTWARE',4,1,0,NULL,NULL,NULL),
(50,21,'Salaries','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SALARY',5,1,0,NULL,NULL,NULL),
(51,21,'Warehouse','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'WAREHOUSE',6,1,0,NULL,NULL,NULL),
(52,21,'Bank & FX fees','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'BANK',7,1,0,NULL,NULL,NULL),
(53,21,'Professional fees','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PROF',8,1,0,NULL,NULL,NULL),
(54,27,'Wise','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'WISE',1,1,0,NULL,NULL,NULL),
(55,27,'Barclays','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'BARCLAYS',2,1,0,NULL,NULL,NULL),
(56,27,'HSBC','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'HSBC',3,1,0,NULL,NULL,NULL),
(57,27,'Monzo','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'MONZO',4,1,0,NULL,NULL,NULL),
(58,27,'Starling','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'STARLING',5,1,0,NULL,NULL,NULL),
(59,27,'Revolut','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'REVOLUT',6,1,0,NULL,NULL,NULL),
(60,27,'Payoneer','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PAYONEER',7,1,0,NULL,NULL,NULL),
(61,1,'Sea LCL','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SEA_LCL',1,1,0,NULL,NULL,NULL),
(62,1,'Sea FCL','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SEA_FCL',2,1,0,NULL,NULL,NULL),
(63,1,'Air','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'AIR',3,1,0,NULL,NULL,NULL),
(64,1,'Rail','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'RAIL',4,1,0,NULL,NULL,NULL),
(65,1,'Truck','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'TRUCK',5,1,0,NULL,NULL,NULL),
(66,1,'Courier','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'COURIER',6,1,0,NULL,NULL,NULL),
(67,8,'FOB','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'FOB',1,1,0,NULL,NULL,NULL),
(68,8,'EXW','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'EXW',2,1,0,NULL,NULL,NULL),
(69,8,'CIF','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'CIF',3,1,0,NULL,NULL,NULL),
(70,8,'DDP','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DDP',4,1,0,NULL,NULL,NULL),
(71,8,'DAP','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DAP',5,1,0,NULL,NULL,NULL),
(72,7,'30% deposit / 70% before shipping','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'30_70',1,1,0,NULL,NULL,NULL),
(73,7,'50% / 50%','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'50_50',2,1,0,NULL,NULL,NULL),
(74,7,'100% before shipping','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'100_PRE',3,1,0,NULL,NULL,NULL),
(75,7,'Net 30','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'NET30',4,1,0,NULL,NULL,NULL),
(76,7,'Pay on collection','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'COLLECT',5,1,0,NULL,NULL,NULL),
(77,5,'Large letter','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'LL',1,1,0,NULL,NULL,NULL),
(78,5,'Small parcel','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SP',2,1,0,NULL,NULL,NULL),
(79,5,'Medium parcel','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'MP',3,1,0,NULL,NULL,NULL),
(80,5,'Box 30Ã—20Ã—10','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'B30',4,1,0,NULL,NULL,NULL),
(81,5,'Box 40Ã—30Ã—20','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'B40',5,1,0,NULL,NULL,NULL),
(82,4,'60Ã—40Ã—34 cm','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'C604034',1,1,0,NULL,NULL,NULL),
(83,4,'50Ã—40Ã—30 cm','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'C504030',2,1,0,NULL,NULL,NULL),
(84,4,'45Ã—35Ã—30 cm','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'C453530',3,1,0,NULL,NULL,NULL),
(85,2,'Ningbo','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'NGB',1,1,0,NULL,NULL,NULL),
(86,2,'Shanghai','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SHA',2,1,0,NULL,NULL,NULL),
(87,2,'Yantian','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'YTN',3,1,0,NULL,NULL,NULL),
(88,2,'Qingdao','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'TAO',4,1,0,NULL,NULL,NULL),
(89,3,'Felixstowe','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'FXT',1,1,0,NULL,NULL,NULL),
(90,3,'Southampton','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SOU',2,1,0,NULL,NULL,NULL),
(91,3,'London Gateway','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'LGP',3,1,0,NULL,NULL,NULL),
(92,10,'CNIC','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'CNIC',1,1,0,NULL,NULL,NULL),
(93,10,'Passport','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PASSPORT',2,1,0,NULL,NULL,NULL),
(94,10,'Aadhaar','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'AADHAAR',3,1,0,NULL,NULL,NULL),
(95,10,'NID','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'NID',4,1,0,NULL,NULL,NULL),
(96,10,'Driving licence','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DL',5,1,0,NULL,NULL,NULL),
(97,9,'Full-time','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'FT',1,1,0,NULL,NULL,NULL),
(98,9,'Part-time','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PT',2,1,0,NULL,NULL,NULL),
(99,9,'Contractor','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'CONTRACT',3,1,0,NULL,NULL,NULL),
(100,9,'Partner','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PARTNER',4,1,0,NULL,NULL,NULL),
(101,9,'Investor','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'INVESTOR',5,1,0,NULL,NULL,NULL),
(102,11,'UK IPO','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'UKIPO',1,1,0,NULL,NULL,NULL),
(103,11,'EUIPO','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'EUIPO',2,1,0,NULL,NULL,NULL),
(104,11,'USPTO','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'USPTO',3,1,0,NULL,NULL,NULL),
(105,11,'WIPO','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'WIPO',4,1,0,NULL,NULL,NULL),
(106,13,'Manufacturer','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'MFR',1,1,0,NULL,NULL,NULL),
(107,13,'Trading company','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'TRADER',2,1,0,NULL,NULL,NULL),
(108,13,'Wholesaler (UK)','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'WHOLESALE',3,1,0,NULL,NULL,NULL),
(109,13,'Forwarder','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'FWD',4,1,0,NULL,NULL,NULL),
(110,13,'Dropship supplier','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DS',5,1,0,NULL,NULL,NULL),
(111,14,'Free sample, buyer pays freight','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'FREE_FREIGHT',1,1,0,NULL,NULL,NULL),
(112,14,'Sample charged, refunded on order','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'REFUND',2,1,0,NULL,NULL,NULL),
(113,14,'Sample charged','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'CHARGED',3,1,0,NULL,NULL,NULL),
(114,15,'eBay message','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'EBAY',1,1,0,NULL,NULL,NULL),
(115,15,'Amazon message','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'AMZ',2,1,0,NULL,NULL,NULL),
(116,15,'E-mail','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'EMAIL',3,1,0,NULL,NULL,NULL),
(117,15,'Phone','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PHONE',4,1,0,NULL,NULL,NULL),
(118,15,'Social','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SOCIAL',5,1,0,NULL,NULL,NULL),
(128,31,'Draft','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DRAFT',10,1,0,NULL,NULL,NULL),
(129,31,'Open','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'OPEN',20,1,0,NULL,NULL,NULL),
(130,31,'Completed','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'COMPLETED',30,1,0,NULL,NULL,NULL),
(131,32,'Completed','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'COMPLETED',10,1,0,NULL,NULL,NULL),
(132,34,'Cleared','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'CLEARED',10,1,0,NULL,NULL,NULL),
(133,34,'Held','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'HELD',20,1,0,NULL,NULL,NULL),
(134,37,'Ocean Freight','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'OCEAN_FREIGHT',10,1,0,NULL,NULL,NULL),
(135,37,'Agent Fee','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'AGENT_FEE',20,1,0,NULL,NULL,NULL),
(136,37,'Other','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'OTHER',30,1,0,NULL,NULL,NULL),
(137,38,'Accepted','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'ACCEPTED',10,1,0,NULL,NULL,NULL),
(138,39,'Posted','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'POSTED',10,1,0,NULL,NULL,NULL),
(139,39,'Pending Approval','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PENDING_APPROVAL',20,1,0,NULL,NULL,NULL),
(140,40,'Completed','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'COMPLETED',10,1,0,NULL,NULL,NULL),
(141,40,'Closed','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'CLOSED',20,1,0,NULL,NULL,NULL),
(142,40,'Received','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'RECEIVED',30,1,0,NULL,NULL,NULL),
(143,40,'Discrepancy','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DISCREPANCY',40,1,0,NULL,NULL,NULL),
(144,41,'Posted','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'POSTED',10,1,0,NULL,NULL,NULL),
(145,41,'Pending Approval','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PENDING_APPROVAL',20,1,0,NULL,NULL,NULL),
(146,41,'Available','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'AVAILABLE',30,1,0,NULL,NULL,NULL),
(147,43,'Received','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'RECEIVED',10,1,0,NULL,NULL,NULL),
(148,46,'Pending','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PENDING',10,1,0,NULL,NULL,NULL),
(149,46,'Partially Paid','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PARTIALLY_PAID',20,1,0,NULL,NULL,NULL),
(150,46,'Paid','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PAID',30,1,0,NULL,NULL,NULL),
(151,47,'Deposit','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DEPOSIT',10,1,0,NULL,NULL,NULL),
(152,47,'Balance','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'BALANCE',20,1,0,NULL,NULL,NULL),
(153,47,'Final','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'FINAL',30,1,0,NULL,NULL,NULL),
(154,49,'Paid','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PAID',10,1,0,NULL,NULL,NULL),
(155,49,'Pending','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PENDING',20,1,0,NULL,NULL,NULL),
(156,50,'Fulfilled','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'FULFILLED',10,1,0,NULL,NULL,NULL),
(157,50,'Damaged','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DAMAGED',20,1,0,NULL,NULL,NULL),
(158,50,'Quarantine','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'QUARANTINE',30,1,0,NULL,NULL,NULL),
(159,51,'Confirmed','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'CONFIRMED',10,1,0,NULL,NULL,NULL),
(160,51,'Completed','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'COMPLETED',20,1,0,NULL,NULL,NULL),
(161,52,'Completed','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'COMPLETED',10,1,0,NULL,NULL,NULL),
(162,52,'Delayed','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DELAYED',20,1,0,NULL,NULL,NULL),
(163,54,'Passed','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PASSED',10,1,0,NULL,NULL,NULL),
(164,54,'Failed','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'FAILED',20,1,0,NULL,NULL,NULL),
(165,56,'Sent','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SENT',10,1,0,NULL,NULL,NULL),
(166,56,'Accepted','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'ACCEPTED',20,1,0,NULL,NULL,NULL),
(167,57,'Approved','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'APPROVED',10,1,0,NULL,NULL,NULL),
(168,57,'Rejected','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'REJECTED',20,1,0,NULL,NULL,NULL),
(169,58,'Sea','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'SEA',10,1,0,NULL,NULL,NULL),
(170,58,'Air','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'AIR',20,1,0,NULL,NULL,NULL),
(171,58,'Rail','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'RAIL',30,1,0,NULL,NULL,NULL),
(172,58,'Road','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'ROAD',40,1,0,NULL,NULL,NULL),
(173,61,'In Transit','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'IN_TRANSIT',10,1,0,NULL,NULL,NULL),
(174,61,'Arrived','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'ARRIVED',20,1,0,NULL,NULL,NULL),
(175,61,'Delivered','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DELIVERED',30,1,0,NULL,NULL,NULL),
(176,61,'Delayed','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'DELAYED',40,1,0,NULL,NULL,NULL),
(177,64,'Received','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'RECEIVED',10,1,0,NULL,NULL,NULL),
(178,64,'Cancelled','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'CANCELLED',20,1,0,NULL,NULL,NULL),
(179,66,'Product Loss','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PRODUCT',10,1,0,NULL,NULL,NULL),
(180,66,'Carrier Loss','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'CARRIER',20,1,0,NULL,NULL,NULL),
(181,66,'Packing Loss','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PACKING',30,1,0,NULL,NULL,NULL),
(182,66,'Carrier + Packing Loss','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'CARRIER_PACKING',40,1,0,NULL,NULL,NULL),
(183,66,'Product + Carrier Loss','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PRODUCT_CARRIER',50,1,0,NULL,NULL,NULL),
(184,66,'Product + Packing Loss','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PRODUCT_PACKING',60,1,0,NULL,NULL,NULL),
(185,66,'Product + Packing + Carrier Loss','2026-11-11 23:31:47','2026-11-11 23:31:47',0,'PRODUCT_CARRIER_PACKING',70,1,0,NULL,NULL,NULL),
(186,24,'Found stock','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'FOUND',50,1,0,NULL,NULL,NULL),
(187,24,'Packing material used','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'PACKING_USE',70,1,0,NULL,NULL,NULL),
(188,24,'Write-off of missing units','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'WRITE_OFF_MISSING',80,1,0,NULL,NULL,NULL),
(189,24,'Write-off of damaged units','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'WRITE_OFF_DAMAGED',90,1,0,NULL,NULL,NULL),
(190,24,'Returned to supplier','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'SUPPLIER_RETURN',100,1,0,NULL,NULL,NULL),
(193,81,'Corrugated board','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'CORRUGATED',1,1,0,NULL,NULL,NULL),
(194,81,'Folding carton (card)','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'CARD',2,1,0,NULL,NULL,NULL),
(195,81,'Kraft paper','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'KRAFT',3,1,0,NULL,NULL,NULL),
(196,81,'Plastic bag','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'POLY',4,1,0,NULL,NULL,NULL),
(197,81,'Blister / clamshell','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'BLISTER',5,1,0,NULL,NULL,NULL),
(198,82,'Conformity certificate / declaration','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'DOC_CONFORMITY',1,1,0,NULL,NULL,NULL),
(199,82,'Test report','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'TEST_REPORT',2,1,0,NULL,NULL,NULL),
(200,82,'Safety data sheet','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'SDS',3,1,0,NULL,NULL,NULL),
(201,82,'Radio / EMC certificate','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'RADIO_EMC',4,1,0,NULL,NULL,NULL),
(202,82,'Water / dust rating report','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'INGRESS',5,1,0,NULL,NULL,NULL),
(203,82,'Food-contact certificate','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'FOOD_CONTACT',6,1,0,NULL,NULL,NULL),
(204,19,'Artwork file','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'ARTWORK',20,1,0,NULL,NULL,NULL),
(205,19,'Dieline','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'DIELINE',21,1,0,NULL,NULL,NULL),
(206,19,'Goods label','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'GOODS_LABEL',22,1,0,NULL,NULL,NULL),
(207,19,'Certificate','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'CERTIFICATE',23,1,0,NULL,NULL,NULL),
(208,19,'Safety data sheet','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'SDS',24,1,0,NULL,NULL,NULL),
(209,19,'Compare report','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'COMPARE_REPORT',25,1,0,NULL,NULL,NULL),
(224,85,'Weekly','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'WEEKLY',1,1,0,NULL,NULL,NULL),
(225,85,'Every two weeks','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'FORTNIGHTLY',2,1,0,NULL,NULL,NULL),
(226,85,'Every four weeks','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'FOUR_WEEKLY',3,1,0,NULL,NULL,NULL),
(227,85,'Monthly','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'MONTHLY',4,1,0,NULL,NULL,NULL),
(228,87,'Quality management certificate','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'QUALITY_MGMT',1,1,0,NULL,NULL,NULL),
(229,87,'Social compliance audit','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'SOCIAL_AUDIT',2,1,0,NULL,NULL,NULL),
(230,87,'Factory audit report','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'FACTORY_AUDIT',3,1,0,NULL,NULL,NULL),
(231,87,'Environmental certificate','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'ENVIRONMENT',4,1,0,NULL,NULL,NULL),
(232,19,'Non-disclosure agreement','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'NDA',30,1,0,NULL,NULL,NULL),
(233,19,'CV / resume','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'CV',31,1,0,NULL,NULL,NULL),
(234,19,'Logo','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'LOGO',32,1,0,NULL,NULL,NULL),
(235,19,'Photo','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'PHOTO',33,1,0,NULL,NULL,NULL),
(236,19,'Bank letter','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'BANK_LETTER',34,1,0,NULL,NULL,NULL),
(237,19,'Business licence','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'LICENCE',35,1,0,NULL,NULL,NULL),
(238,19,'Agreement','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'AGREEMENT',36,1,0,NULL,NULL,NULL),
(239,19,'Product specification','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'SPEC',37,1,0,NULL,NULL,NULL),
(240,19,'Quotation','2026-11-11 23:31:48','2026-11-11 23:31:48',0,'QUOTE',38,1,0,NULL,NULL,NULL);
DROP TABLE IF EXISTS `role_permission_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permission_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `route_path` varchar(255) NOT NULL,
  `route_name` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sidebar_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sidebar_modules` (
  `id_module` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(60) NOT NULL,
  `module_name` varchar(120) NOT NULL,
  `icon` varchar(60) DEFAULT NULL,
  `url` varchar(120) DEFAULT NULL,
  `parent_id` int(11) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `e_status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `department` varchar(60) DEFAULT NULL,
  `f_new_tab` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_module`),
  UNIQUE KEY `module_key` (`module_key`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `sidebar_modules` VALUES
(90100,'SEC_1','Home',NULL,NULL,NULL,10,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90101,'M_1_1_OWNER_HOME_COMMAND_CENTER_','Owner Home (\"Command Center\")',NULL,'#/home (role OWNER)',90100,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90102,'M_1_2_PARTNER_HOME_MY_PARTNER_ACCOUNT_','Partner Home (= My Partner Account)',NULL,'#/home (role PARTNER)',90100,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90103,'M_1_3_MANAGER_HOME','Manager Home',NULL,'#/home (role MANAGER)',90100,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90104,'M_1_4_VA_TL_HOME','VA / TL Home',NULL,'#/home (role VA, TL)',90100,4,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90105,'M_1_5_WAREHOUSE_HOME','Warehouse Home',NULL,'#/home (role WAREHOUSE, STOCK)',90100,5,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90106,'M_1_6_FINANCE_HOME','Finance Home',NULL,'#/home (role FINANCE)',90100,6,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90107,'M_1_7_ACCOUNTANT_HOME','Accountant Home',NULL,'#/home (role ACCOUNTANT)',90100,7,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90200,'SEC_2','Research',NULL,NULL,NULL,20,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90201,'M_2_1_RESEARCH_BOARD','Research board',NULL,'#/research',90200,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90202,'M_2_2_RESEARCH_RECORD','Research record',NULL,'#/research/view/{id}',90200,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90300,'SEC_3','Buying',NULL,NULL,NULL,30,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90301,'M_3_1_BUYING_DASHBOARD','Buying dashboard',NULL,'#/part/2/buying',90300,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90302,'M_3_2_SOURCING_CYCLES','Sourcing cycles',NULL,'#/part/2/cycles',90300,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90303,'M_3_3_CYCLE_WORKSPACE_ONE_CYCLE_STEP_BAR_','Cycle workspace (one cycle, step bar)',NULL,'#/cycle/{id}',90300,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90304,'M_3_4_QUICK_BUY_LOCAL_PURCHASE_','Quick buy (local purchase)',NULL,'#/create/quick_buy',90300,4,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90305,'M_3_5_OLD_PURCHASE_ENTRY_BACK_DATED_','Old purchase entry (back-dated)',NULL,'#/create/old_entry',90300,5,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90306,'M_3_6_DIRECT_PO_REPEAT_ORDER','Direct PO — repeat order',NULL,'#/create/t_src_direct',90300,6,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90307,'M_3_7_RFQ_QUOTATIONS','RFQ & quotations',NULL,'#/part/2/rfq',90300,7,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90308,'M_3_8_SAMPLES','Samples',NULL,'#/cycle/{id}?tab=samples (+ list)',90300,8,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90309,'M_3_9_PURCHASE_ORDERS','Purchase orders',NULL,'#/part/2/po',90300,9,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90310,'M_3_10_PAYMENTS_TERMS','Payments & terms',NULL,'#/part/2/payments',90300,10,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90311,'M_3_11_PRODUCTION','Production',NULL,'#/cycle/{id}?tab=production',90300,11,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90312,'M_3_12_QC','QC',NULL,'#/cycle/{id}?tab=qc',90300,12,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90313,'M_3_13_ALLOCATION_PLATFORM_PLAN','Allocation & platform plan',NULL,'#/cycle/{id}?tab=allocation',90300,13,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90314,'M_3_14_SHIPMENTS','Shipments',NULL,'#/part/2/shipments',90300,14,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90315,'M_3_15_FREIGHT','Freight',NULL,'#/cycle/{id}?tab=freight',90300,15,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90316,'M_3_16_CUSTOMS','Customs',NULL,'#/cycle/{id}?tab=customs',90300,16,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90317,'M_3_17_LANDED_COST','Landed cost',NULL,'#/part/2/landed',90300,17,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90318,'M_3_18_SUPPLIERS_FORWARDERS','Suppliers & forwarders',NULL,'#/list/suppliers',90300,18,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90400,'SEC_4','Receiving & Warehouse',NULL,NULL,NULL,40,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90401,'M_4_1_STOCK_WAREHOUSE_DASHBOARD','Stock & warehouse dashboard',NULL,'#/part/2/stockdash',90400,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90402,'M_4_2_INBOUND_GRN','Inbound & GRN',NULL,'#/part/2/grn',90400,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90403,'M_4_3_WORK_SCREEN_PHONE_RECEIVE_PUTAWAY_PICK_PACK_HANDOVER_C','Work screen (phone) – Receive · Putaway · Pick · Pack · Handover · Count · Returns · Move',NULL,'#/work',90400,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90404,'M_4_4_LOCATIONS_BINS','Locations & bins',NULL,'#/list/locations',90400,4,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90405,'M_4_5_SKU_HOMES','SKU homes',NULL,'#/list/sku-homes',90400,5,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90406,'M_4_6_STOCK_ON_HAND_CENTRAL_INVENTORY_','Stock on hand (Central inventory)',NULL,'#/list/inventory',90400,6,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90407,'M_4_7_STOCK_LEDGER_ADJUSTMENTS','Stock ledger & adjustments',NULL,'#/list/ledger',90400,7,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90408,'M_4_8_LOTS_OWNERS','Lots & owners',NULL,'#/list/lots',90400,8,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90409,'M_4_9_POOL','Pool',NULL,'#/part/2/pool',90400,9,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90410,'M_4_10_TRANSFERS','Transfers',NULL,'#/list/transfers',90400,10,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90411,'M_4_11_OWNERSHIP_TRANSFERS','Ownership transfers',NULL,'#/list/ownership',90400,11,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90412,'M_4_12_STOCK_COUNTS','Stock counts',NULL,'#/part/2/counts',90400,12,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90413,'M_4_13_HELD_STOCK_RETURNS_QUARANTINE_DAMAGED_MISSING_','Held stock (returns, quarantine, damaged, missing)',NULL,'#/list/held',90400,13,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90414,'M_4_14_REPLENISHMENT_ICC_REORDER','Replenishment & ICC reorder',NULL,'#/icc',90400,14,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90415,'M_4_15_PACKAGING_BOX_SIZES','Packaging & box sizes',NULL,'#/list/packaging',90400,15,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90500,'SEC_5','Products & Listings',NULL,NULL,NULL,50,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90501,'M_5_1_PRODUCTS','Products',NULL,'#/list/products',90500,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90502,'M_5_2_PRODUCT_RECORD','Product record',NULL,'#/product/{id}',90500,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90503,'M_5_3_PRODUCT_PACK_ARTWORK_','Product pack (artwork)',NULL,'#/product/{id}/pack',90500,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90504,'M_5_4_CERTIFICATES','Certificates',NULL,'#/list/certificates',90500,4,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90505,'M_5_5_LEGAL_LABEL_TEXTS','Legal label texts',NULL,'#/list/legal-texts',90500,5,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90506,'M_5_6_LISTINGS_PER_MARKETPLACE_PROFILE_','Listings (per marketplace profile)',NULL,'#/list/listings',90500,6,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90507,'M_5_7_PRICES_PRICE_LOG','Prices & price log',NULL,'#/list/price-log',90500,7,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90508,'M_5_8_PRODUCTS_NOT_LISTED','Products not listed',NULL,'#/list/notlisted',90500,8,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90509,'M_5_9_BRANDS','Brands',NULL,'#/list/brands',90500,9,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90600,'SEC_6','Selling',NULL,NULL,NULL,60,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90601,'M_6_1_SELLING_DASHBOARD','Selling dashboard',NULL,'#/part/3/selling',90600,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90602,'M_6_2_ORDERS_SALES_LINES','Orders / sales lines',NULL,'#/list/sales',90600,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90603,'M_6_3_DAILY_ORDER_INTAKE_FULFILMENT_','Daily order intake (fulfilment)',NULL,'#/list/daily-orders',90600,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90604,'M_6_4_MARKETPLACE_OPERATIONS_HUBS','Marketplace operations hubs',NULL,'#/ops/{profile}',90600,4,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90605,'M_6_5_SALES_MONTH_CONFIRM_BATCH_PAGE_','Sales month confirm (batch page)',NULL,'#/importbatch/{id}',90600,5,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90606,'M_6_6_REFUNDS_REFUND_PROPOSALS','Refunds & refund proposals',NULL,'#/list/refunds',90600,6,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90607,'M_6_7_DROPSHIPPING','Dropshipping',NULL,'#/part/3/dropship',90600,7,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90608,'M_6_8_ACCOUNT_HEALTH_SHOP_REMINDERS','Account health & shop reminders',NULL,'#/part/3/health',90600,8,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90609,'M_6_9_MARKETPLACE_CALCULATOR','Marketplace calculator',NULL,'#/tools/marketplace-calc',90600,9,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90700,'SEC_7','Dispatch',NULL,NULL,NULL,70,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90701,'M_7_1_ORDERS_TO_DISPATCH','Orders to dispatch',NULL,'#/dispatch',90700,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90702,'M_7_2_PICK_LISTS','Pick lists',NULL,'#/list/pick',90700,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90703,'M_7_3_PACK','Pack',NULL,'#/work?tab=pack',90700,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90704,'M_7_4_LABELS_PRINT_QUEUE','Labels & print queue',NULL,'#/labels',90700,4,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90705,'M_7_5_COURIER_HANDOVER','Courier handover',NULL,'#/list/handover',90700,5,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90706,'M_7_6_TRACKING_UPLOAD','Tracking upload',NULL,'#/labels?tab=tracking',90700,6,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90707,'M_7_7_FC_3PL_INBOUND_SHIPMENTS','FC / 3PL inbound shipments',NULL,'#/list/inbound-fc',90700,7,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90800,'SEC_8','Returns & Customers',NULL,NULL,NULL,80,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90801,'M_8_1_RETURNS','Returns',NULL,'#/part/3/returns',90800,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90802,'M_8_2_CUSTOMER_SERVICE_CASES','Customer service cases',NULL,'#/part/3/cs',90800,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90803,'M_8_3_CUSTOMERS','Customers',NULL,'#/list/customers',90800,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90900,'SEC_9','Money',NULL,NULL,NULL,90,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90901,'M_9_1_MONEY_DASHBOARD','Money dashboard',NULL,'#/part/3/money',90900,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90902,'M_9_2_BANK_CASH','Bank & cash',NULL,'#/list/bank',90900,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90903,'M_9_3_PAYOUTS_SETTLEMENTS','Payouts & settlements',NULL,'#/list/payouts',90900,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90904,'M_9_4_RECONCILIATION','Reconciliation',NULL,'#/recon',90900,4,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90905,'M_9_5_EXPENSES','Expenses',NULL,'#/part/3/expenses',90900,5,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90906,'M_9_6_APPROVALS_MONEY_VIEW_','Approvals – money (view)',NULL,'#/approvals?area=money',90900,6,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90907,'M_9_7_CAPITAL_CALLS','Capital calls',NULL,'#/part/3/capital',90900,7,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90908,'M_9_8_WITHDRAWALS','Withdrawals',NULL,'#/list/withdrawals',90900,8,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90909,'M_9_9_FX_RATES','FX rates',NULL,'#/part/3/fx',90900,9,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90910,'M_9_10_PAYABLES_CASH_FORECAST','Payables & cash forecast',NULL,'#/list/commitments',90900,10,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90911,'M_9_11_LOANS','Loans',NULL,'#/list/loans',90900,11,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90912,'M_9_12_BUDGETS','Budgets',NULL,'#/list/budgets',90900,12,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90913,'M_9_13_FINANCE_ALERTS','Finance alerts',NULL,'#/list/fin-alerts',90900,13,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(90914,'M_9_14_LEDGER_ACCOUNTANT_VIEW_','Ledger (accountant view)',NULL,'#/list/ledger-fin',90900,14,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91000,'SEC_10','Month Close & Audit',NULL,NULL,NULL,100,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91001,'M_10_1_AUDIT_DASHBOARD','Audit dashboard',NULL,'#/part/3/auditdash',91000,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91002,'M_10_2_MONTH_CLOSE_SHOP_MONTH_','Month close (shop × month)',NULL,'#/audit/{shop}/{month}',91000,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91003,'M_10_3_PROFIT_VIEWS','Profit views',NULL,'#/audit/profit',91000,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91004,'M_10_4_AUDIT_RUNS_ORDERS_AND_PURCHASE_AUDITS_','Audit runs (orders and purchase audits)',NULL,'#/list/audit',91000,4,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91005,'M_10_5_SHARED_EXPENSE_RULES','Shared expense rules',NULL,'#/list/expense-rules',91000,5,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91006,'M_10_6_VA_STATEMENTS','VA statements',NULL,'#/list/va-statements',91000,6,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91007,'M_10_7_INVOICES_CREDIT_NOTES','Invoices & credit notes',NULL,'#/list/invoices',91000,7,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91008,'M_10_8_TAX_FILINGS_COMPLIANCE_CALENDAR_','Tax & filings (compliance calendar)',NULL,'#/part/3/tax',91000,8,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91009,'M_10_9_TAXATION_ACCOUNTS_WORKSHEET_CORPORATION_TAX_','Taxation (accounts worksheet, Corporation Tax)',NULL,'#/tax',91000,9,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91010,'M_10_10_OPENING_BALANCES','Opening balances',NULL,'#/finance/opening',91000,10,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91100,'SEC_11','Partners & Investors',NULL,NULL,NULL,110,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91101,'M_11_1_PARTNERS_DASHBOARD','Partners dashboard',NULL,'#/part/3/partners',91100,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91102,'M_11_2_AGREEMENTS_WITH_STACKING_RULE_','Agreements (with stacking rule)',NULL,'#/list/agreements',91100,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91103,'M_11_3_PROFIT_SPLIT_RULES','Profit split rules',NULL,'#/part/3/splits',91100,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91104,'M_11_4_STATEMENTS','Statements',NULL,'#/part/3/statements',91100,4,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91105,'M_11_5_MY_PARTNER_ACCOUNT','My Partner Account',NULL,'#/home?as=PARTNER',91100,5,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91106,'M_11_6_PARTNER_LEDGER','Partner ledger',NULL,'#/list/partner-ledger',91100,6,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91107,'M_11_7_INVESTOR_INVOICING_SETTLE_A_MONTH_','Investor invoicing (settle a month)',NULL,'#/m/t_invoices/settle',91100,7,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91108,'M_11_8_SOURCING_INVESTMENTS_LEGACY_INVESTORS_','Sourcing investments (legacy investors)',NULL,'#/list/src-investments',91100,8,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91200,'SEC_12','People & Accounts',NULL,NULL,NULL,120,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91201,'M_12_1_ACCOUNTS_DASHBOARD','Accounts dashboard',NULL,'#/part/4/accounts',91200,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91202,'M_12_2_PEOPLE_COMPANIES_PERSON_RECORD_','People & companies (person record)',NULL,'#/list/parties',91200,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91203,'M_12_3_STAFF_EMPLOYMENT','Staff & employment',NULL,'#/list/staff',91200,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91204,'M_12_4_COMPANIES','Companies',NULL,'#/list/companies',91200,4,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91205,'M_12_5_SHOPS_SELLER_ACCOUNTS','Shops & seller accounts',NULL,'#/list/shops',91200,5,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91206,'M_12_6_ACCOUNT_KIT','Account kit',NULL,'#/part/4/kit',91200,6,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91207,'M_12_7_SUBSCRIPTIONS','Subscriptions',NULL,'#/list/subscriptions',91200,7,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91208,'M_12_8_DUE_DATES','Due dates',NULL,'#/list/due',91200,8,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91209,'M_12_9_MERGE_DUPLICATES','Merge duplicates',NULL,'#/part/4/merge',91200,9,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91300,'SEC_13','Work',NULL,NULL,NULL,130,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91301,'M_13_1_TASKS_DASHBOARD','Tasks dashboard',NULL,'#/part/4/taskdash',91300,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91302,'M_13_2_NEEDS_ACTION_ALL_TASKS_','Needs action (all tasks)',NULL,'#/list/tasks',91300,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91303,'M_13_3_MY_TASKS','My tasks',NULL,'#/list/tasks?mine=1',91300,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91304,'M_13_4_APPROVALS_QUEUE','Approvals queue',NULL,'#/approvals',91300,4,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91305,'M_13_5_TEAM_SETTINGS_TASK_RULES_','Team & settings (task rules)',NULL,'#/tasks/team',91300,5,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91306,'M_13_6_MESSAGES_WHATSAPP','Messages & WhatsApp',NULL,'#/part/4/messages',91300,6,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91307,'M_13_7_PLAYBOOKS','Playbooks',NULL,'#/list/playbooks',91300,7,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91400,'SEC_14','Documents',NULL,NULL,NULL,140,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91401,'M_14_1_DOCUMENTS','Documents',NULL,'#/list/documents',91400,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91402,'M_14_2_DRIVE_FOLDERS','Drive folders',NULL,'#/part/4/drive',91400,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91500,'SEC_15','Import Center',NULL,NULL,NULL,150,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91501,'M_15_1_IMPORT_A_FILE','Import a file',NULL,'#/import',91500,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91502,'M_15_2_IMPORT_HISTORY','Import history',NULL,'#/list/imports',91500,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91503,'M_15_3_WATCHED_DRIVE_FOLDERS','Watched Drive folders',NULL,'#/importfolders',91500,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91504,'M_15_4_MAPPINGS_REPORT_TYPES','Mappings & report types',NULL,'#/import/mappings',91500,4,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91505,'M_15_5_API_CONNECTIONS','API connections',NULL,'#/import/api',91500,5,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91600,'SEC_16','Reports',NULL,NULL,NULL,160,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91601,'M_16_1_ALL_REPORTS_GROUPED_','All reports (grouped)',NULL,'#/part/4/reports',91600,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91700,'SEC_17','Setup',NULL,NULL,NULL,170,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91701,'M_17_1_FIRST_TIME_SETUP_WIZARD','First-time setup wizard',NULL,'#/setup/start',91700,1,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91702,'M_17_2_COMPANY_FINANCE_SETTINGS','Company & finance settings',NULL,'#/settings/finance',91700,2,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91703,'M_17_3_MARKETPLACE_PROFILES','Marketplace profiles',NULL,'#/setup/marketplaces',91700,3,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91704,'M_17_4_CARRIER_PROFILES_PRESETS','Carrier profiles & presets',NULL,'#/setup/carriers',91700,4,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91705,'M_17_5_BANK_STATEMENT_PROFILES','Bank statement profiles',NULL,'#/setup/bank-profiles',91700,5,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91706,'M_17_6_PAYMENT_PROVIDER_PROFILES','Payment-provider profiles',NULL,'#/setup/provider-profiles',91700,6,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91707,'M_17_7_3PL_PROFILES','3PL profiles',NULL,'#/setup/3pl-profiles',91700,7,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91708,'M_17_8_DROPSHIP_SUPPLIER_PROFILES','Dropship-supplier profiles',NULL,'#/setup/dropship-profiles',91700,8,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91709,'M_17_9_PRINTERS_MEDIA_ROUTES_TEST_PAGE','Printers, media, routes, test page',NULL,'#/settings/printer',91700,9,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91710,'M_17_10_LABEL_TEMPLATES_SHEET_LAYOUTS','Label templates & sheet layouts',NULL,'#/setup/labels',91700,10,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91711,'M_17_11_LABEL_REGULATION_PROFILES','Label regulation profiles',NULL,'#/setup/label-regulation',91700,11,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91712,'M_17_12_REFERENCE_REGISTER','Reference Register',NULL,'#/refs',91700,12,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91713,'M_17_13_CATALOG_MASTERS','Catalog masters',NULL,'#/setup/catalogs',91700,13,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91714,'M_17_14_FIELD_REGISTRY','Field Registry',NULL,'#/fields',91700,14,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91715,'M_17_15_PERMISSIONS_ROLES','Permissions & roles',NULL,'#/perms',91700,15,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91716,'M_17_16_SESSIONS_IP_ALLOW_LIST','Sessions & IP allow-list',NULL,'#/settings/sessions',91700,16,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91717,'M_17_17_AUDIT_LOG','Audit log',NULL,'#/settings/audit-log',91700,17,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91718,'M_17_18_SETTINGS','Settings',NULL,'#/part/4/settings',91700,18,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91719,'M_17_19_MIGRATION_SWITCH','Migration switch',NULL,'#/settings/cutover',91700,19,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0),
(91720,'M_17_20_SYSTEM_HEALTH_JOBS','System health & jobs',NULL,'#/events',91700,20,'ACTIVE','2026-11-12 04:31:48',NULL,NULL,NULL,0);
DROP TABLE IF EXISTS `sidebar_modules_01`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sidebar_modules_01` (
  `id_module` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(60) NOT NULL,
  `module_name` varchar(120) NOT NULL,
  `icon` varchar(60) DEFAULT NULL,
  `url` varchar(120) DEFAULT NULL,
  `parent_id` int(11) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `e_status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id_module`),
  UNIQUE KEY `module_key` (`module_key`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sidebar_modules_backup_20261019`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sidebar_modules_backup_20261019` (
  `id_module` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(60) NOT NULL,
  `module_name` varchar(120) NOT NULL,
  `icon` varchar(60) DEFAULT NULL,
  `url` varchar(120) DEFAULT NULL,
  `parent_id` int(11) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `e_status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id_module`),
  UNIQUE KEY `module_key` (`module_key`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sidebar_modules_before_v17`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sidebar_modules_before_v17` (
  `id_module` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(60) NOT NULL,
  `module_name` varchar(120) NOT NULL,
  `icon` varchar(60) DEFAULT NULL,
  `url` varchar(120) DEFAULT NULL,
  `parent_id` int(11) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `e_status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `department` varchar(60) DEFAULT NULL,
  `f_new_tab` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_module`),
  UNIQUE KEY `module_key` (`module_key`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `social_media_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `social_media_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_social_media_id` int(11) NOT NULL,
  `question` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `src_research`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `src_research` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `research_code` varchar(30) NOT NULL,
  `title` varchar(200) NOT NULL,
  `fk_id_brand` int(11) DEFAULT NULL,
  `fk_id_category` int(11) DEFAULT NULL,
  `mp_profiles` varchar(200) DEFAULT NULL,
  `target_country` varchar(60) DEFAULT NULL,
  `keywords` text DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'IDEA',
  `est_monthly_units` int(11) DEFAULT NULL,
  `competitor_price_low` decimal(12,2) DEFAULT NULL,
  `competitor_price_high` decimal(12,2) DEFAULT NULL,
  `target_sell_price` decimal(12,2) DEFAULT NULL,
  `est_unit_cost` decimal(12,4) DEFAULT NULL,
  `est_freight_per_unit` decimal(12,4) DEFAULT NULL,
  `est_fee_pct` decimal(5,2) DEFAULT NULL,
  `est_ad_pct` decimal(5,2) DEFAULT NULL,
  `currency` varchar(3) DEFAULT NULL,
  `est_margin_pct` decimal(6,2) DEFAULT NULL,
  `demand_level` varchar(8) DEFAULT NULL,
  `competition_level` varchar(8) DEFAULT NULL,
  `seasonality` varchar(120) DEFAULT NULL,
  `risk_flags` varchar(200) DEFAULT NULL,
  `fk_id_product` int(11) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `decision_reason` text DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `f_deleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_res` (`fk_id_ent`,`research_code`),
  KEY `ix_res_status` (`fk_id_ent`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Product research / ideas (R-B_SOURCING-67…70)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `src_research_competitors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `src_research_competitors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_research` int(11) NOT NULL,
  `fk_id_mp_profile` int(11) DEFAULT NULL,
  `listing_url` varchar(500) NOT NULL,
  `seller_name` varchar(120) DEFAULT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `est_monthly_sold` int(11) DEFAULT NULL,
  `review_count` int(11) DEFAULT NULL,
  `rating` decimal(3,2) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `checked_at` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_rc` (`fk_id_research`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Competitor listings seen during research';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `src_research_suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `src_research_suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_research` int(11) NOT NULL,
  `fk_id_supplier` int(11) DEFAULT NULL,
  `supplier_name_text` varchar(150) DEFAULT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `unit_price` decimal(12,4) DEFAULT NULL,
  `currency` varchar(3) DEFAULT NULL,
  `moq` int(11) DEFAULT NULL,
  `lead_days` smallint(6) DEFAULT NULL,
  `sample_available` tinyint(4) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_rs` (`fk_id_research`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Supplier candidates found during research';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `subscription_items_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_items_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_item_id` int(11) NOT NULL,
  `question` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_approval_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_approval_limits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_role` int(11) NOT NULL,
  `action_code` varchar(40) NOT NULL,
  `max_amount_gbp` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lim` (`fk_id_ent`,`fk_id_role`,`action_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_approval_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_approval_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rule_code` varchar(40) NOT NULL,
  `entity_table` varchar(64) NOT NULL,
  `column_pattern` varchar(120) NOT NULL,
  `approver_role` varchar(30) NOT NULL DEFAULT 'OWNER',
  `risk` enum('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'MEDIUM',
  `f_requires_phone_check` tinyint(4) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rule` (`rule_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `sys_approval_rules` VALUES
(1,'SUP_BANK','t_account_banks','%','OWNER','HIGH',1,1),
(2,'PARTY_ROLE_OWNER','t_account_roles','%','OWNER','HIGH',0,1),
(3,'LOT_OWNER','fin_lots','fk_id_owner','OWNER','HIGH',0,1),
(4,'PRICE_FLOOR','t_product_listings','price_min','OWNER','MEDIUM',0,1),
(5,'COMPANY_SETTINGS','t_enterprise','%','OWNER','MEDIUM',0,1),
(6,'APPROVAL_LIMITS','sys_approval_limits','%','OWNER','HIGH',0,1),
(7,'STOCK_ADJUST_BIG','t_stock_movements','qty','OWNER','MEDIUM',0,1),
(8,'EXPENSE_RULE','aud_expense_rules','%','OWNER','MEDIUM',0,1),
(9,'SRC_OLD_ENTRY','t_src_cycles','approval_status','PARTNER','MEDIUM',0,1),
(10,'SRC_OVERPAY','t_src_payments','payment_amount','OWNER','MEDIUM',0,1),
(11,'SRC_LC_OVERRIDE','t_src_landed_cost','landed_cost_per_unit','OWNER','MEDIUM',0,1);
DROP TABLE IF EXISTS `sys_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_approvals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `action_code` varchar(40) NOT NULL,
  `entity` varchar(60) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  `amount_gbp` decimal(14,2) DEFAULT NULL,
  `requested_by` int(11) NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `fk_id_task` int(11) DEFAULT NULL,
  `entity_table` varchar(64) DEFAULT NULL,
  `change_json` text DEFAULT NULL,
  `risk` enum('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'MEDIUM',
  `phone_confirmed_by` int(11) DEFAULT NULL,
  `phone_confirmed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_open` (`status`,`fk_id_ent`),
  KEY `ix_entity` (`entity`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_audit_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `table_name` varchar(64) NOT NULL,
  `record_id` bigint(20) NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE','RESTORE','MERGE','UNMERGE','REVEAL','LOGIN','LOGIN_FAIL','EXPORT','APPROVE','REJECT','LOCK','UNLOCK','LOGOUT','FORCE_LOGOUT','IP_BLOCKED','KEY_ROTATED','CUTOVER','ROLLBACK') NOT NULL,
  `changes_json` longtext DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_rec` (`table_name`,`record_id`),
  KEY `ix_user` (`user_id`,`created_at`),
  KEY `ix_audit_ent_day` (`fk_id_ent`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_cutover_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_cutover_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_company_house` int(11) NOT NULL,
  `module_code` varchar(40) NOT NULL,
  `cutover_date` date NOT NULL,
  `cutover_status` varchar(12) NOT NULL DEFAULT 'PLANNED',
  `fk_id_compare_doc` int(11) DEFAULT NULL,
  `compare_equal` tinyint(4) DEFAULT NULL,
  `switched_by` int(11) DEFAULT NULL,
  `switched_at` datetime DEFAULT NULL,
  `rollback_by` int(11) DEFAULT NULL,
  `rollback_at` datetime DEFAULT NULL,
  `rollback_reason` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cut` (`fk_id_company_house`,`module_code`,`cutover_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Migration screen: compare, switch per company, rollback (09 §8)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_document_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_document_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_document` int(11) NOT NULL,
  `entity` varchar(40) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_link` (`fk_id_document`,`entity`,`entity_id`),
  KEY `ix_entity` (`entity`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `doc_no` varchar(30) NOT NULL,
  `fk_id_doc_type` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `drive_url` varchar(500) DEFAULT NULL,
  `drive_file_id` varchar(120) DEFAULT NULL,
  `stored_name` varchar(160) DEFAULT NULL,
  `orig_name` varchar(200) DEFAULT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `size_bytes` int(11) DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `ref_no` varchar(100) DEFAULT NULL,
  `d_doc` date DEFAULT NULL,
  `d_expire` date DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `fk_id_previous` int(11) DEFAULT NULL,
  `status` enum('ACTIVE','REPLACED','DELETED') NOT NULL DEFAULT 'ACTIVE',
  `legacy_table` varchar(40) DEFAULT NULL,
  `legacy_id` int(11) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `tags` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `doc_no` (`doc_no`),
  KEY `ix_type` (`fk_id_ent`,`fk_id_doc_type`),
  KEY `ix_hash` (`sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_dq_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_dq_scores` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `form_key` varchar(60) NOT NULL,
  `record_id` bigint(20) NOT NULL,
  `score_pct` tinyint(4) NOT NULL,
  `missing_fields` text DEFAULT NULL,
  `computed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dq` (`form_key`,`record_id`),
  KEY `ix_dq_score` (`fk_id_ent`,`form_key`,`score_pct`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Data-quality score per record (R-G_CORE-108)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_drive_folders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_drive_folders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity` varchar(40) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  `folder_id` varchar(120) NOT NULL,
  `folder_url` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_f` (`entity`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_due_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_due_items` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_source` int(11) NOT NULL,
  `record_id` bigint(20) NOT NULL,
  `due_date` date NOT NULL,
  `title` varchar(200) NOT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  `status` enum('UPCOMING','REMINDED','OVERDUE','DONE','IGNORED') NOT NULL DEFAULT 'UPCOMING',
  `fk_id_task` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item` (`fk_id_source`,`record_id`,`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_due_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_due_sources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_table` varchar(64) NOT NULL,
  `date_column` varchar(64) NOT NULL,
  `title_template` varchar(200) NOT NULL,
  `owner_rule` varchar(80) NOT NULL,
  `remind_days` int(11) NOT NULL DEFAULT 7,
  `done_rule` varchar(120) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `code` varchar(40) DEFAULT NULL,
  `name` varchar(120) DEFAULT NULL,
  `task_code` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_src` (`source_table`,`date_column`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `sys_due_sources` VALUES
(1,'t_account_subscriptions','next_due_date','Pay subscription #{id}','record.fk_id_account',5,'date moved forward or bank line matched',1,NULL,NULL,'ACC_DUE_ITEM'),
(2,'t_account_subscriptions','billing_date','Billing date subscription #{id}','record.fk_id_account',5,'date moved forward',1,NULL,NULL,'ACC_DUE_ITEM'),
(3,'t_account_ipburgers','renewal_due','Renew IP account #{id}','record.fk_id_account',7,'date moved forward',1,NULL,NULL,'ACC_DUE_ITEM'),
(4,'t_account_ipburgers','ipb_expires','IP expires #{id}','record.fk_id_account',7,'date moved forward',1,NULL,NULL,'ACC_DUE_ITEM'),
(5,'t_account_credit_cards','valid_upto','Card ending {card_last4} expires','record.fk_id_account',30,'date moved forward',1,'CARD_EXPIRY','Card expiry','ACC_DUE_ITEM'),
(6,'t_account_photo_ids','photo_expiry_date','Photo ID expires #{id}','record.fk_id_account',45,'date moved forward',1,NULL,NULL,'ACC_DUE_ITEM'),
(7,'t_account_doc','d_expire','Document expires #{id}','record.fk_id_account',30,'date moved forward',1,NULL,NULL,'ACC_DUE_ITEM'),
(8,'t_account_company_houses','confirmation_statement_date','Confirmation statement #{id}','role:ACCOUNTANT',30,'date moved forward',1,NULL,NULL,'ACC_DUE_ITEM'),
(9,'t_account_company_houses','file_return_date','Accounts filing #{id}','role:ACCOUNTANT',30,'date moved forward',1,NULL,NULL,'ACC_DUE_ITEM'),
(10,'acc_obligations','due_date','{title}','record.fk_assigned',14,'status in FILED,PAID,NOT_REQUIRED',1,'FILING','Filing or payment (to the compliance owner)','ACC_COMPLIANCE_DUE'),
(11,'t_src_payments','payment_date','Supplier payment {payment_code}','role:FINANCE',3,'status = Paid',1,NULL,NULL,'ACC_DUE_ITEM'),
(12,'t_account_mobiles','next_recharge_date','SIM recharge #{id}','record.fk_id_account',7,'date moved forward',1,'SIM_RECHARGE','SIM recharge','ACC_DUE_ITEM'),
(13,'t_account_credit_cards','f_card_billing_payment_date','Card bill #{id}','record.fk_id_account',7,'date moved forward',1,'CARD_BILL','Card bill','ACC_DUE_ITEM'),
(14,'t_account_job_history','d_contract_renewal_date','Contract renewal #{id}','record.fk_id_account',7,'date moved forward',1,'CONTRACT_RENEWAL','Contract renewal','ACC_DUE_ITEM'),
(15,'t_account_job_history','salary_date','Salary date #{id}','record.fk_id_account',7,'date moved forward',1,'SALARY_DATE','Salary date','ACC_DUE_ITEM'),
(16,'product_certificates','expiry_date','Certificate expires #{id}','product.owner',60,'date moved forward',1,'CERT_EXPIRY','Product certificate expiry','PRD_CERT_EXPIRY');
DROP TABLE IF EXISTS `sys_error_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_error_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `level` enum('ERROR','WARNING','NOTICE','EXCEPTION','DB') NOT NULL,
  `message` text NOT NULL,
  `file` varchar(255) DEFAULT NULL,
  `line` int(11) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `context_json` text DEFAULT NULL,
  `fingerprint` char(40) NOT NULL,
  `times_seen` int(11) NOT NULL DEFAULT 1,
  `f_alerted` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fp` (`fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_event_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_event_runs` (
  `fk_id_event` bigint(20) NOT NULL,
  `listener` varchar(120) NOT NULL,
  `status` enum('DONE','FAILED') NOT NULL,
  `message` varchar(500) DEFAULT NULL,
  `run_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`fk_id_event`,`listener`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_event_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_event_types` (
  `event_code` varchar(60) NOT NULL,
  `area` varchar(30) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`event_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Canonical event catalogue (06_ENGINES §D)';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `sys_event_types` VALUES
('API_SYNC_EXPIRED','Platform','engine results',1),
('API_SYNC_FAILED','Platform','engine results',1),
('API_SYNC_OK','Platform','engine results',1),
('APPROVAL_APPROVED','Approvals','maker-checker result',1),
('APPROVAL_REJECTED','Approvals','maker-checker result',1),
('APPROVAL_REQUESTED','Platform','maker-checker',1),
('ARTWORK_APPROVED','Masters','Artwork version approved (R-D-90)',1),
('BANK_LINE_MATCHED','Finance','a bank line matched (any rule or target)',1),
('BANK_LINE_UNMATCHED','Finance','a bank match undone',1),
('BULK_APPLIED','Platform','bulk job done / undone',1),
('BULK_UNDONE','Platform','bulk job done / undone',1),
('CAPITAL_CALL_ACCEPTED','Finance','partner answers / money matched',1),
('CAPITAL_RECEIVED','Finance','partner answers / money matched',1),
('CASH_LOW','Platform','engine results',1),
('CERTIFICATE_SAVED','Masters','Product certificate saved (R-D-91)',1),
('CHECK_WAIVED','Finance','month close steps',1),
('COST_CORRECTED','Sourcing','a FINAL lot cost corrected (new version)',1),
('COUNT_SUBMITTED','Warehouse','Count submitted',1),
('CREDIT_NOTE_ISSUED','Invoices','invoice cancelled / credit note',1),
('CUTOVER_ROLLED_BACK','Platform','Company × module rolled back (09 §8)',1),
('CUTOVER_SWITCHED','Platform','Company × module switched to the new tool (09 §8)',1),
('DOC_ADDED','Documents','document linked',1),
('DS_BATCH_APPLIED','Import','dropship supplier file applied',1),
('DUE_ITEM_CLOSED','Platform','Due_engine',1),
('DUE_SOON','Platform','Due_engine',1),
('ENGAGEMENT_CHANGED','Masters','role, kind or job row changed',1),
('EXPORT_DONE','System','platform results',1),
('FBA_SHIPMENT_CLOSED','Labels','fulfilment-centre inbound shipment steps',1),
('FX_MISSING','Platform','engine results',1),
('GRN_POSTED','Sourcing','GRN posted (sources PO, PURCHASE, OPENING, HISTORICAL)',1),
('HANDOVER_CLOSED','Warehouse','Handover session closed',1),
('HISTORICAL_ENTRY_APPROVED','Sourcing','old entry approved',1),
('ICC_OK','Platform','engine results',1),
('ICC_RED','Platform','engine results',1),
('ICC_YELLOW','Platform','engine results',1),
('IMPORT_APPLIED','Import','an Import Center batch saved / undone',1),
('IMPORT_UNDONE','Import','an Import Center batch saved / undone',1),
('INBOUND_CARTONS_IMPORTED','Labels','fulfilment-centre inbound shipment steps',1),
('INVOICE_CANCELLED','Invoices','invoice cancelled / credit note',1),
('INVOICE_CREATED','Sales','invoice document generated',1),
('LABEL_IMPORTED','Labels','label steps (18_Labels)',1),
('LABEL_PRINTED','Labels','label steps (18_Labels)',1),
('LABEL_REPRINTED','Labels','label steps (18_Labels)',1),
('LABEL_VOIDED','Labels','Shipping label voided',1),
('LANDED_FINAL','Sourcing','a landed run version becomes FINAL',1),
('LANDED_PROVISIONAL','Sourcing','landed cost version saved as PROVISIONAL',1),
('LISTING_SAVED','Masters','product / listing saved',1),
('LOCATIONS_GENERATED','Warehouse','Location generator batch created',1),
('LOT_OWNER_CHANGED','Stock','lot owner changed (pool cover / transfer between owners)',1),
('MONTH_CHECKED','Finance','a close-check run finishes',1),
('MONTH_LOCKED','Finance','a shop-month is locked',1),
('MONTH_VERSIONED','Finance','month close steps',1),
('OWNERSHIP_TRANSFERRED','Stock','the Stock_service action',1),
('PACKAGING_APPROVED','Masters','Packaging row approved (R-D-89)',1),
('PARCEL_HANDED_OVER','Warehouse','Parcel handed to the carrier (ship)',1),
('PARCEL_LOST','Warehouse','Lost-parcel case opened',1),
('PARCEL_PACKED','Warehouse','Parcel verified',1),
('PARCEL_READY','Warehouse','Parcel labelled and ready',1),
('PAYMENT_APPROVED','Sourcing','supplier payment needs / gets approval',1),
('PAYMENT_POSTED','Sourcing','supplier payment saved as paid (typed or created by bank match)',1),
('PAYMENT_REQUESTED','Sourcing','supplier payment needs / gets approval',1),
('PAYMENT_REVERSED','Sourcing','a posted payment reversed',1),
('PERIOD_OPENED','Finance','a shop-month (or placeholder) opens',1),
('PERSON_CREATED','Masters','a person (t_account) is saved',1),
('PERSON_KIND_CHANGED','Masters','role, kind or job row changed',1),
('PERSON_MERGED','Masters','two persons merged',1),
('PERSON_ROLE_ADDED','Masters','role, kind or job row changed',1),
('PERSON_UPDATED','Masters','a person (t_account) is saved',1),
('PICK_CONFIRMED','Warehouse','Units picked to dispatch',1),
('PICK_LIST_BUILT','Warehouse','Pick task created',1),
('PO_CONFIRMED','Sourcing','PO status Confirmed (incl. Direct PO save)',1),
('PO_SENT','Sourcing','PO sent to the supplier',1),
('PRICE_CHANGED','Masters','price period / listing price changed',1),
('PRINTER_ADDED','Labels','printer profile created (setup wizard or manual)',1),
('PRINTER_REPLACED','Labels','printer status REPAIR / RETIRED and its routes moved to another printer',1),
('PRINTER_TESTED','Labels','test page printed; result and calibration saved',1),
('PRINT_FAILED','Labels','a job could not be printed (offline, media too small, no route)',1),
('PRINT_JOB_FAILED','Labels','Print job failed',1),
('PRODUCT_OOS','Masters','product goes out of stock',1),
('PRODUCT_SAVED','Masters','product / listing saved',1),
('PROFILE_SAVED','Masters','marketplace / carrier / bank / 3PL profile saved (IC-0)',1),
('PUTAWAY_DONE','Warehouse','Units put away to a bin',1),
('QC_SAVED','Sourcing','QC row saved',1),
('RECOMMENDATION','Platform','engine results',1),
('REFUND_ACCEPTED','Sales','refund proposal created / accepted',1),
('REFUND_PROPOSED','Sales','refund proposal created / accepted',1),
('REF_ADDED','Masters','a reference value added (PENDING)',1),
('RESEARCH_GO','Sourcing','research decided GO – draft product and sourcing cycle created',1),
('RESEARCH_SAVED','Sourcing','research record saved / status changed',1),
('RESERVATION_RELEASED','Warehouse','Reservation released or expired',1),
('RETURN_RECEIVED','Stock','return booked as received (unconsume)',1),
('RETURN_SCANNED','Warehouse','Return intake saved',1),
('RIGHTS_DENIED','Platform','a refused action',1),
('RIGHT_CHANGED','System','platform results',1),
('SALE_DAILY_CLOSED','Sales','\"Close daily orders\" for a daily shop',1),
('SALE_MONTH_CONFIRMED','Sales','Confirm sales of one batch (the only sale consumer)',1),
('SALE_MONTH_UNCONFIRMED','Sales','a confirmed sales batch re-opened (open month only)',1),
('SESSION_FORCED_OUT','Platform','Admin ended a user session (R-C-49)',1),
('SETTLEMENT_CONFIRMED','Sales','an investor-invoicing month is settled',1),
('SHIPMENT_SAVED','Sourcing','shipment saved / ETA changed',1),
('SHOP_ACCESS_EXPIRED','Masters','assignment \"to\" date passed (R-C, cli/Rights_scope_refresh)',1),
('SHOP_ACCESS_GRANTED','Masters','shop assignment added / ended',1),
('SHOP_ACCESS_REVOKED','Masters','shop assignment added / ended',1),
('SHORT_PICK','Warehouse','Picker found fewer units',1),
('SKU_HOME_CHANGED','Warehouse','SKU home set or moved',1),
('SKU_MAP_SAVED','Masters','Channel SKU map saved',1),
('STATEMENT_QUERY','Finance','partner action',1),
('STATEMENT_READY','Finance','month close steps',1),
('STEP_BLOCKED','Sourcing','workflow step state changes',1),
('STEP_DONE','Sourcing','workflow step state changes',1),
('STEP_OVERRIDDEN','Workflow','step forced past a block with reason',1),
('STEP_UNSKIPPED','Sourcing','workflow step state changes',1),
('STOCK_ADJUSTED','Stock','the Stock_service action',1),
('STOCK_BIN_MOVED','Warehouse','Bin-to-bin physical move',1),
('STOCK_CONSUMED','Stock','per sale line inside SALE_MONTH_CONFIRMED',1),
('STOCK_COUNT_POSTED','Stock','the Stock_service action',1),
('STOCK_RESERVED','Warehouse','Reservation created or changed',1),
('STOCK_TRANSFERRED','Stock','the Stock_service action',1),
('TASK_ACK','Platform','task state moves (R-G_CORE-1)',1),
('TASK_APPROVED','Platform','task state moves (R-G_CORE-1)',1),
('TASK_CANCELLED','Platform','task state moves (R-G_CORE-1)',1),
('TASK_CREATED','Platform','task state moves (R-G_CORE-1)',1),
('TASK_DONE','Platform','task state moves (R-G_CORE-1)',1),
('TASK_DUE_MOVED','Platform','task state moves (R-G_CORE-1)',1),
('TASK_IMPORTED','Platform','task state moves (R-G_CORE-1)',1),
('TASK_REASSIGNED','Platform','task state moves (R-G_CORE-1)',1),
('TASK_REWORK','Platform','task state moves (R-G_CORE-1)',1),
('TASK_SNOOZED','Platform','task state moves (R-G_CORE-1)',1),
('TASK_STARTED','Platform','task state moves (R-G_CORE-1)',1),
('TASK_STUCK','Platform','task state moves (R-G_CORE-1)',1),
('TASK_UNBLOCKED','Platform','task state moves (R-G_CORE-1)',1),
('TRACKING_UPLOADED','Labels','label steps (18_Labels)',1),
('VA_STATEMENT_APPROVED','Sales','VA statement approved',1),
('VISIBILITY_CHANGED','Masters','product / listing saved',1),
('WA_SEND_FAILED','System','platform results',1),
('WH_RECON_DONE','Warehouse','Reconciliation run finished',1),
('WITHDRAWAL_REQUESTED','Finance','partner action',1);
DROP TABLE IF EXISTS `sys_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `event_code` varchar(60) NOT NULL,
  `entity` varchar(60) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  `payload_json` text DEFAULT NULL,
  `status` enum('NEW','RUNNING','DONE','FAILED') NOT NULL DEFAULT 'NEW',
  `attempts` tinyint(4) NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `available_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_queue` (`status`,`available_at`),
  KEY `ix_entity` (`entity`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_field_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_field_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `list_code` varchar(120) NOT NULL,
  `value` varchar(120) NOT NULL,
  `label` varchar(160) NOT NULL,
  `sort` smallint(6) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opt` (`list_code`,`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `sys_field_options` VALUES
(1,'FULFILMENT_CHANNEL','SELF','Own dispatch',10,1),
(2,'FULFILMENT_CHANNEL','MARKETPLACE_FC','Marketplace fulfilment centre',20,1),
(3,'FULFILMENT_CHANNEL','THREE_PL','Third-party warehouse',30,1),
(4,'FULFILMENT_CHANNEL','DROPSHIP','Dropship supplier',40,1),
(5,'FULFILMENT_CHANNEL','MIXED','Mixed',50,1),
(6,'PROFIT_ROUTE','LOT_PROFIT','Lot profit (default, provisional)',10,1),
(7,'PROFIT_ROUTE','INVESTOR_INVOICING','Investor invoicing',20,1),
(8,'GRN_SOURCE','PO','Purchase order',10,1),
(9,'GRN_SOURCE','PURCHASE','Local purchase',20,1),
(10,'GRN_SOURCE','OPENING','Opening stock',30,1),
(11,'GRN_SOURCE','HISTORICAL','Old entry',40,1),
(12,'TASK_PRIORITY','URGENT','Urgent',10,1),
(13,'TASK_PRIORITY','HIGH','High',20,1),
(14,'TASK_PRIORITY','NORMAL','Normal',30,1),
(15,'TASK_PRIORITY','LOW','Low',40,1),
(16,'TASK_STATUS','TODO','To do',10,1),
(17,'TASK_STATUS','DOING','Doing',20,1),
(18,'TASK_STATUS','STUCK','Stuck',30,1),
(19,'TASK_STATUS','DONE','Done',40,1),
(20,'TASK_STATUS','REWORK','Rework',50,1),
(21,'TASK_STATUS','APPROVED','Approved',60,1),
(22,'TASK_STATUS','CANCELLED','Cancelled',70,1),
(23,'REPEAT_TYPE','NONE','Does not repeat',10,1),
(24,'REPEAT_TYPE','DAILY','Every day',20,1),
(25,'REPEAT_TYPE','WEEKLY','Every week',30,1),
(26,'REPEAT_TYPE','MONTHLY','Every month',40,1),
(27,'REPEAT_TYPE','EVERY_N_DAYS','Every N days',50,1),
(28,'WEEKDAY','1','Monday',10,1),
(29,'WEEKDAY','2','Tuesday',20,1),
(30,'WEEKDAY','3','Wednesday',30,1),
(31,'WEEKDAY','4','Thursday',40,1),
(32,'WEEKDAY','5','Friday',50,1),
(33,'WEEKDAY','6','Saturday',60,1),
(34,'WEEKDAY','7','Sunday',70,1),
(35,'MONTH','1','January',10,1),
(36,'MONTH','2','February',20,1),
(37,'MONTH','3','March',30,1),
(38,'MONTH','4','April',40,1),
(39,'MONTH','5','May',50,1),
(40,'MONTH','6','June',60,1),
(41,'MONTH','7','July',70,1),
(42,'MONTH','8','August',80,1),
(43,'MONTH','9','September',90,1),
(44,'MONTH','10','October',100,1),
(45,'MONTH','11','November',110,1),
(46,'MONTH','12','December',120,1),
(47,'LOCATION_KIND','WAREHOUSE','Warehouse',10,1),
(48,'LOCATION_KIND','SHELF','Shelf',20,1),
(49,'LOCATION_KIND','MARKETPLACE_FULFILMENT','Marketplace fulfilment centre',30,1),
(50,'LOCATION_KIND','THREE_PL','Third-party warehouse',40,1),
(51,'LOCATION_KIND','TRANSIT','In transit (not sellable)',50,1),
(52,'LOCATION_KIND','PRODUCTION','At production (not sellable)',60,1),
(53,'LOCATION_KIND','SUPPLIER','At supplier (not sellable)',70,1),
(54,'LOCATION_KIND','DAMAGED','Damaged (not sellable)',80,1),
(55,'LOCATION_KIND','CUSTOMER','With customer (not sellable)',90,1),
(56,'LOCATION_KIND','RETURNS','Returns area (not sellable)',100,1),
(57,'LINE_TYPE','PRODUCT','Product (stock)',10,1),
(58,'LINE_TYPE','FEE','Fee (no stock)',20,1),
(59,'LINE_TYPE','COST','Cost (no stock)',30,1),
(60,'LINE_TYPE','SAMPLE','Sample (never stock)',40,1),
(61,'CYCLE_TYPE','FULL','Full cycle',10,1),
(62,'CYCLE_TYPE','DIRECT','Direct PO (repeat order)',20,1),
(63,'CYCLE_TYPE','QUICK','Quick buy',30,1),
(64,'CYCLE_TYPE','HISTORICAL','Old entry',40,1),
(65,'SALE_SOURCE','active','Own stock',10,1),
(66,'SALE_SOURCE','ds','Dropship',20,1),
(67,'SALE_SOURCE','unmatched','Not matched',30,1),
(68,'SALE_SOURCE','summary','Daily totals',40,1),
(69,'SALE_SOURCE','FULFILMENT','Daily fulfilment intake',50,1),
(70,'SALE_SOURCE','MANUAL','Manual sale',60,1),
(71,'SALE_LINE_STATUS','Pending','Pending',10,1),
(72,'SALE_LINE_STATUS','Confirmed','Confirmed',20,1),
(73,'SALE_LINE_STATUS','Refunded','Refunded',30,1),
(74,'LABEL_STATUS','TO_SHIP','To ship',10,0),
(75,'LABEL_STATUS','LABEL_READY','Label ready',20,0),
(76,'LABEL_STATUS','PRINTED','Printed',30,1),
(77,'LABEL_STATUS','SHIPPED','Shipped',40,0),
(78,'LABEL_STATUS','CANCELLED','Cancelled',50,0),
(79,'REFUND_KIND','FULL','Full',10,1),
(80,'REFUND_KIND','HALF','Half (49–51 %)',20,1),
(81,'REFUND_KIND','PARTIAL','Partial',30,1),
(82,'REFUND_KIND','OUTSIDE','Outside the order',40,1),
(83,'PROPOSAL_STATUS','OPEN','Open',10,1),
(84,'PROPOSAL_STATUS','ACCEPTED','Accepted',20,1),
(85,'PROPOSAL_STATUS','REJECTED','Rejected',30,1),
(86,'RETURN_RESTOCK','SAME_LOT','Back to the same lot (sellable)',10,1),
(87,'RETURN_RESTOCK','DAMAGED','Damaged location',20,1),
(88,'RETURN_RESTOCK','RETURNS_AREA','Returns area',30,1),
(89,'RETURN_RESTOCK','WRITTEN_OFF','Written off',40,1),
(90,'PAID_VIA','BANK','Bank',10,1),
(91,'PAID_VIA','CREDIT_NOTE','Supplier credit note',20,1),
(92,'PAY_STATUS','UNPAID','Unpaid',10,1),
(93,'PAY_STATUS','PART_PAID','Part paid',20,1),
(94,'PAY_STATUS','PAID','Paid',30,1),
(95,'CLAIM_STATUS','OPEN','Open',10,1),
(96,'CLAIM_STATUS','SENT','Sent to supplier',20,1),
(97,'CLAIM_STATUS','AGREED','Agreed',30,1),
(98,'CLAIM_STATUS','SETTLED','Settled',40,1),
(99,'CLAIM_STATUS','REJECTED','Rejected',50,1),
(100,'CLAIM_SETTLED_AS','CREDIT_NOTE','Credit note',10,1),
(101,'CLAIM_SETTLED_AS','REFUND','Refund',20,1),
(102,'CLAIM_SETTLED_AS','REPLACEMENT','Replacement goods',30,1),
(103,'CLAIM_SETTLED_AS','WRITTEN_OFF','Written off',40,1),
(104,'DUE_RULE','ON_PO_CONFIRM','On PO confirm',10,1),
(105,'DUE_RULE','BEFORE_SHIPMENT','Before shipment',20,1),
(106,'DUE_RULE','ON_BL_DATE','On bill of lading date',30,1),
(107,'DUE_RULE','ON_ARRIVAL','On arrival',40,1),
(108,'DUE_RULE','DAYS_AFTER_INVOICE','Days after invoice',50,1),
(109,'DUE_RULE','FIXED_DATE','Fixed date',60,1),
(110,'TERM_MILESTONE','DEPOSIT','Deposit',10,1),
(111,'TERM_MILESTONE','BEFORE_SHIPMENT','Before shipment',20,1),
(112,'TERM_MILESTONE','BALANCE','Balance',30,1),
(113,'TERM_MILESTONE','ON_DELIVERY','On delivery',40,1),
(114,'TERM_MILESTONE','NET','Net days',50,1),
(115,'LANDED_STATUS','PROVISIONAL','Provisional',10,1),
(116,'LANDED_STATUS','FINAL','Final',20,1),
(117,'LANDED_STATUS','SUPERSEDED','Superseded',30,1),
(118,'SPLIT_METHOD','Quantity','Quantity',10,1),
(119,'SPLIT_METHOD','Value','Value',20,1),
(120,'SPLIT_METHOD','Weight','Weight',30,1),
(121,'SPLIT_METHOD','CBM','CBM',40,1),
(122,'PARTY_ROLE','VA','VA (alias SELLER)',10,1),
(123,'PARTY_ROLE','TL','Team leader',20,1),
(124,'PARTY_ROLE','MANAGER','Manager',30,1),
(125,'PARTY_ROLE','PARTNER','Partner',40,1),
(126,'PARTY_ROLE','OWNER','Owner',50,1),
(127,'PARTY_ROLE','FINANCE','Finance',60,1),
(128,'PARTY_ROLE','WAREHOUSE','Warehouse',70,1),
(129,'PARTY_ROLE','STOCK','Stock',80,1),
(130,'PARTY_ROLE','ADMIN','Admin',90,1),
(131,'PARTY_ROLE','SUPPLIER','Supplier',100,1),
(132,'PARTY_ROLE','FORWARDER','Forwarder',110,1),
(133,'PARTY_ROLE','INVESTOR','Investor',120,1),
(134,'PARTY_ROLE','DIRECTOR','Director',130,1),
(135,'PARTY_ROLE','ACCOUNTANT','Accountant',140,1),
(136,'PARTY_ROLE','HANDLER','Handler',150,1),
(137,'PARTY_ROLE','REQUESTER_3PL','Requester (3PL)',160,1),
(138,'PARTY_TYPE','P','Person',10,1),
(139,'PARTY_TYPE','C','Company',20,1),
(140,'ACCOUNT_KIND','M','Managed account',10,1),
(141,'ACCOUNT_KIND','E','External contact',20,1),
(142,'ESCALATE_ROLE','MANAGER','Manager',10,1),
(143,'ESCALATE_ROLE','PARTNER','Partner',20,1),
(144,'EVIDENCE_TYPE','SCREENSHOT','Screenshot',10,1),
(145,'EVIDENCE_TYPE','PHOTO','Photo',20,1),
(146,'EVIDENCE_TYPE','FILE','File',30,1),
(147,'EVIDENCE_TYPE','LINK','Link',40,1),
(148,'EVIDENCE_TYPE','REFERENCE','Reference',50,1),
(149,'TEMPLATE_OWNER_ROLE','PARTNER','Partner',10,1),
(150,'TEMPLATE_OWNER_ROLE','MANAGER','Manager',20,1),
(151,'TEMPLATE_OWNER_ROLE','VA','VA',30,1),
(152,'TEMPLATE_OWNER_ROLE','CATEGORY_DEFAULT','Category default',40,1),
(153,'EVENT_CODE','PO_CONFIRMED','Po confirmed',10,1),
(154,'EVENT_CODE','PO_SENT','Po sent',20,1),
(155,'EVENT_CODE','GRN_POSTED','Grn posted',30,1),
(156,'EVENT_CODE','LANDED_FINAL','Landed final',40,1),
(157,'EVENT_CODE','SALE_MONTH_CONFIRMED','Sale month confirmed',50,1),
(158,'EVENT_CODE','STOCK_CONSUMED','Stock consumed',60,1),
(159,'EVENT_CODE','MONTH_LOCKED','Month locked',70,1),
(160,'EVENT_CODE','IMPORT_APPLIED','Import applied',80,1),
(161,'EVENT_CODE','PRODUCT_OOS','Product oos',90,1),
(162,'EVENT_CODE','PRICE_CHANGED','Price changed',100,1),
(163,'EVENT_CODE','SETTLEMENT_CONFIRMED','Settlement confirmed',110,1),
(164,'EVENT_CODE','PERIOD_OPENED','Period opened',120,1),
(165,'EVENT_CODE','STEP_DONE','Step done',130,1),
(166,'EVENT_CODE','STEP_BLOCKED','Step blocked',140,1),
(167,'EVENT_CODE','DOC_ADDED','Doc added',150,1),
(168,'EVENT_CODE','REF_ADDED','Ref added',160,1),
(169,'EVENT_CODE','RIGHTS_DENIED','Rights denied',170,1),
(170,'EVENT_CODE','PAYMENT_REVERSED','Payment reversed',180,1),
(171,'EVENT_CODE','PERSON_MERGED','Person merged',190,1),
(172,'EVENT_CODE','VA_STATEMENT_APPROVED','Va statement approved',200,1),
(173,'EVENT_CODE','APPROVAL_REQUESTED','Approval requested',210,1),
(174,'LINK_MODULE','NONE','No record',10,1),
(175,'LINK_MODULE','PRODUCT','Product',20,1),
(176,'LINK_MODULE','CYCLE','Sourcing cycle',30,1),
(177,'LINK_MODULE','PO','Purchase order',40,1),
(178,'LINK_MODULE','SHIPMENT','Shipment',50,1),
(179,'LINK_MODULE','GRN','GRN',60,1),
(180,'LINK_MODULE','SALE','Sale line',70,1),
(181,'LINK_MODULE','SHOP','Shop',80,1),
(182,'LINK_MODULE','PERSON','Person',90,1),
(183,'LINK_MODULE','COMPANY','Company',100,1),
(184,'LINK_MODULE','INVOICE','Invoice',110,1),
(185,'LINK_MODULE','BANK_LINE','Bank line',120,1),
(186,'MODULE','Sales','Sales',10,1),
(187,'MODULE','Sourcing','Sourcing',20,1),
(188,'MODULE','Stock','Stock',30,1),
(189,'MODULE','Finance','Finance',40,1),
(190,'MODULE','Accounts','Accounts',50,1),
(191,'MODULE','Products','Products',60,1),
(192,'MODULE','Import','Import',70,1),
(193,'MODULE','Audit','Audit',80,1),
(194,'MODULE','Data quality','Data quality',90,1),
(195,'MODULE','System','System',100,1),
(196,'MODULE','Labels','Labels',110,1),
(197,'MODULE','Tasks','Tasks',120,1),
(198,'TIMEZONE','UTC','UTC',10,1),
(199,'TIMEZONE','Europe/London','Europe/London',20,1),
(200,'TIMEZONE','Europe/Berlin','Europe/Berlin',30,1),
(201,'TIMEZONE','Asia/Karachi','Asia/Karachi',40,1),
(202,'TIMEZONE','Asia/Shanghai','Asia/Shanghai',50,1),
(203,'TIMEZONE','Asia/Dubai','Asia/Dubai',60,1),
(204,'TIMEZONE','America/New_York','America/New_York',70,1),
(205,'ADDRESS_TYPE','O','Office',10,1),
(206,'ADDRESS_TYPE','H','Home',20,1),
(207,'PAYOUT_MODE','Daily','Daily',10,1),
(208,'PAYOUT_MODE','Weekly','Weekly',20,1),
(209,'PAYOUT_MODE','Fortnightly','Fortnightly',30,1),
(210,'PAYOUT_MODE','Monthly','Monthly',40,1),
(211,'ACCOUNT_TYPE','Personal','Personal',10,1),
(212,'ACCOUNT_TYPE','Business','Business',20,1),
(213,'BANK_CARD_TYPE','Debit','Debit',10,1),
(214,'CC_CARD_TYPE','Debit','Debit',10,1),
(215,'CC_CARD_TYPE','Credit','Credit',20,1),
(216,'BILLING_CYCLE','Monthly','Monthly',10,1),
(217,'BILLING_CYCLE','Quarterly','Quarterly',20,1),
(218,'BILLING_CYCLE','Yearly','Yearly',30,1),
(219,'MOBILE_CAPACITY','1','1 Sim Mobile',10,1),
(220,'MOBILE_CAPACITY','2','2 Sim Mobile',20,1),
(221,'MOBILE_CAPACITY','4','4 Sim Mobile',30,1),
(222,'RECHARGE_CONTRACT','R','Recharge',10,1),
(223,'RECHARGE_CONTRACT','C','Contract',20,1),
(224,'SIM_SLOT','1','1',10,1),
(225,'SIM_SLOT','2','2',20,1),
(226,'SIM_SLOT','3','3',30,1),
(227,'SIM_SLOT','4','4',40,1),
(228,'TITLE','Mr','Mr',10,1),
(229,'TITLE','Mrs','Mrs',20,1),
(230,'TITLE','Miss','Miss',30,1),
(231,'TITLE','Ms','Ms',40,1),
(232,'TITLE','Dr','Dr',50,1),
(233,'EC_COMPANY_MODE','none','No company',10,1),
(234,'EC_COMPANY_MODE','new','New company',20,1),
(235,'EC_COMPANY_MODE','existing','Existing company',30,1),
(236,'CASE_STATUS','PENDING','Pending',10,1),
(237,'CASE_STATUS','RESOLVED','Resolved',20,1),
(238,'COMPANY_CREATION','SELF','Self',10,1),
(239,'COMPANY_CREATION','Virtual Company','Virtual company',20,1),
(240,'PRESET_SCOPE','ALL','All marketplaces and shops',10,1),
(241,'PRESET_SCOPE','LIMITED','Only the chosen ones',20,1),
(242,'VISIBILITY_MODE','pool','Pool (every active shop)',10,1),
(243,'VISIBILITY_MODE','private','Private (ticked shops)',20,1),
(244,'TM_STATUS','NOT_FILED','Not filed',10,1),
(245,'TM_STATUS','FILED','Filed',20,1),
(246,'TM_STATUS','REGISTERED','Registered',30,1),
(247,'TM_STATUS','OPPOSED','Opposed',40,1),
(248,'TM_STATUS','EXPIRED','Expired',50,1),
(249,'OBLIGATION_STATUS','PENDING','Pending',10,1),
(250,'OBLIGATION_STATUS','IN_PROGRESS','In progress',20,1),
(251,'OBLIGATION_STATUS','FILED','Filed',30,1),
(252,'OBLIGATION_STATUS','PAID','Paid',40,1),
(253,'OBLIGATION_STATUS','NOT_REQUIRED','Not required',50,1),
(254,'OBLIGATION_AREA','VAT','Vat',10,1),
(255,'OBLIGATION_AREA','CT','Ct',20,1),
(256,'OBLIGATION_AREA','CS','Cs',30,1),
(257,'OBLIGATION_AREA','ACCOUNTS','Accounts',40,1),
(258,'OBLIGATION_AREA','PAYROLL','Payroll',50,1),
(259,'OBLIGATION_AREA','PERSONAL','Personal',60,1),
(260,'OBLIGATION_AREA','OTHER','Other',70,1),
(261,'ACC_ENTITY_TYPE','COMPANY','Company',10,1),
(262,'ACC_ENTITY_TYPE','PERSON','Person',20,1),
(263,'QUESTION_OWNER','BANK','Bank',10,1),
(264,'QUESTION_OWNER','CARD_PROVIDER','Card provider',20,1),
(265,'QUESTION_OWNER','SOCIAL','Social media',30,1),
(266,'QUESTION_OWNER','SUBSCRIPTION','Subscription',40,1),
(267,'QUESTION_OWNER','PAYPAL','PayPal',50,1),
(268,'QUESTION_OWNER','COMPANIES_HOUSE','Companies House',60,1),
(269,'API_STATUS','NEW','New',10,1),
(270,'API_STATUS','CONNECTED','Connected',20,1),
(271,'API_STATUS','EXPIRED','Expired',30,1),
(272,'API_STATUS','ERROR','Error',40,1),
(273,'API_STATUS','OFF','Off',50,1),
(274,'EXPENSE_SOURCE','MANUAL','Typed',10,1),
(275,'EXPENSE_SOURCE','BANK','From a bank line',20,1),
(276,'EXPENSE_SOURCE','IMPORT','Imported',30,1),
(277,'BATCH_STATUS','UPLOADED','Uploaded',10,1),
(278,'BATCH_STATUS','MAPPING','Mapping',20,1),
(279,'BATCH_STATUS','PREVIEW','Preview',30,1),
(280,'BATCH_STATUS','SAVED','Saved',40,1),
(281,'BATCH_STATUS','PARTIAL','Partial',50,1),
(282,'BATCH_STATUS','UNDONE','Undone',60,1),
(283,'BATCH_STATUS','FAILED','Failed',70,1),
(284,'INVOICE_TYPE','INVESTOR','Investor',10,1),
(285,'INVOICE_TYPE','SELF_SETTLEMENT','Self settlement',20,1),
(286,'INVOICE_TYPE','OWNER_STATEMENT','Owner statement',30,1),
(287,'INVOICE_TYPE','VA','VA',40,1),
(288,'INVOICE_TYPE','SBS_3PL','3PL',50,1),
(289,'INVOICE_TYPE','SBS_HANDLER','Handler',60,1),
(290,'INVOICE_TYPE','CREDIT_NOTE','Credit note',70,1),
(291,'INVOICE_TYPE','INVOICE','Invoice',80,1),
(292,'INVOICE_STATUS','UNPAID','Unpaid',10,1),
(293,'INVOICE_STATUS','PART_PAID','Part paid',20,1),
(294,'INVOICE_STATUS','PAID','Paid',30,1),
(295,'INVOICE_STATUS','CANCELLED','Cancelled',40,1),
(296,'INVOICE_STATUS','NOT_REQUIRED','Not required',50,1),
(297,'INVOICE_STATUS','CREDITED','Credited',60,1),
(298,'INVOICE_LINE_GROUP','CAPITAL','Capital',10,1),
(299,'INVOICE_LINE_GROUP','PROFIT_SHARE','Profit share',20,1),
(300,'INVOICE_LINE_GROUP','FEE','Fee',30,1),
(301,'INVOICE_LINE_GROUP','EXPENSE','Expense',40,1),
(302,'INVOICE_LINE_GROUP','ADJUSTMENT','Adjustment',50,1),
(303,'CREDIT_DIRECTION','IN','Money comes in',10,1),
(304,'CREDIT_DIRECTION','OUT','Money goes out',20,1),
(305,'CREDIT_USE','REFUND_DUE','Refund due',10,1),
(306,'CREDIT_USE','APPLY_NEXT','Apply to the next invoice',20,1),
(307,'SETTLE_METHOD','BANK','Bank',10,1),
(308,'SETTLE_METHOD','CREDIT_NOTE','Credit note',20,1),
(309,'SETTLE_METHOD','OFFSET','Offset',30,1),
(310,'STATEMENT_STATUS','DRAFT','Draft',10,1),
(311,'STATEMENT_STATUS','PENDING_APPROVAL','Waiting for approval',20,1),
(312,'STATEMENT_STATUS','APPROVED','Approved',30,1),
(313,'STATEMENT_STATUS','SENT','Sent',40,1),
(314,'STATEMENT_STATUS','SUPERSEDED','Superseded',50,1),
(315,'COMMITMENT_STATUS','PLANNED','Planned',10,1),
(316,'COMMITMENT_STATUS','COMMITTED','Committed',20,1),
(317,'COMMITMENT_STATUS','PAID','Paid',30,1),
(318,'COMMITMENT_STATUS','CANCELLED','Cancelled',40,1),
(319,'SPLIT_TARGET','FREIGHT','Freight cost',10,1),
(320,'SPLIT_TARGET','DUTY','Customs duty',20,1),
(321,'SPLIT_TARGET','IMPORT_VAT','Import VAT',30,1),
(322,'SPLIT_TARGET','EXPENSE','Expense',40,1),
(323,'SPLIT_TARGET','OTHER','Other',50,1),
(324,'MATCH_TARGET','SUPPLIER_PAYMENT','Supplier payment',10,1),
(325,'MATCH_TARGET','FREIGHT','Freight',20,1),
(326,'MATCH_TARGET','DUTY','Duty',30,1),
(327,'MATCH_TARGET','EXPENSE','Expense',40,1),
(328,'MATCH_TARGET','CLAIM','Claim',50,1),
(329,'MATCH_TARGET','INVOICE','Invoice',60,1),
(330,'MATCH_TARGET','CAPITAL_CALL','Capital call',70,1),
(331,'MATCH_TARGET','WITHDRAWAL','Withdrawal',80,1),
(332,'MATCH_TARGET','PAYOUT','Payout',90,1),
(333,'MATCH_TARGET','VA_SHARE','Va share',100,1),
(334,'MATCH_TARGET','SPLIT','Split',110,1),
(335,'MATCH_STATUS','UNMATCHED','Unmatched',10,1),
(336,'MATCH_STATUS','AUTO','Auto',20,1),
(337,'MATCH_STATUS','MANUAL','Manual',30,1),
(338,'MATCH_STATUS','IGNORED','Ignored',40,1),
(339,'CLOSE_CHECK','FILES_PRESENT','Files present',10,1),
(340,'CLOSE_CHECK','SETTLEMENT_COMPLETE','Settlement complete',20,1),
(341,'CLOSE_CHECK','ORDERS_VS_TXN','Orders vs txn',30,1),
(342,'CLOSE_CHECK','HUB_TOTALS','Hub totals',40,1),
(343,'CLOSE_CHECK','PAYOUT_VS_BANK','Payout vs bank',50,1),
(344,'CLOSE_CHECK','BANK_ALL_CATEGORISED','Bank all categorised',60,1),
(345,'CLOSE_CHECK','PURCHASE_VS_SUPPLIER','Purchase vs supplier',70,1),
(346,'CLOSE_CHECK','SALES_CONFIRMED','Sales confirmed',80,1),
(347,'CLOSE_CHECK','COST_PRESENT','Cost present',90,1),
(348,'CLOSE_CHECK','FX_PRESENT','Fx present',100,1),
(349,'CLOSE_CHECK','EXPENSES','Expenses',110,1),
(350,'CLOSE_CHECK','STOCK_ROLL','Stock roll',120,1),
(351,'CLOSE_CHECK','SHARES_LE_100','Shares le 100',130,1),
(352,'CLOSE_CHECK','SPLIT_RULES','Split rules',140,1),
(353,'CLOSE_CHECK','OPENING_LOCKED','Opening locked',150,1),
(354,'CLOSE_CHECK','PREVIOUS_LOCKED','Previous locked',160,1),
(355,'CLOSE_CHECK','ONE_PROFIT_ROUTE','One profit route',170,1),
(356,'CLOSE_CHECK_WAIVABLE','ORDERS_VS_TXN','Orders vs txn',10,1),
(357,'CLOSE_CHECK_WAIVABLE','HUB_TOTALS','Hub totals',20,1),
(358,'CLOSE_CHECK_WAIVABLE','PURCHASE_VS_SUPPLIER','Purchase vs supplier',30,1),
(359,'CLOSE_CHECK_WAIVABLE','EXPENSES','Expenses',40,1),
(360,'CLOSE_CHECK_WAIVABLE','STOCK_ROLL','Stock roll',50,0),
(361,'PERIOD_STATUS','OPEN','Open',10,1),
(362,'PERIOD_STATUS','CHECKING','Checking',20,0),
(363,'PERIOD_STATUS','CLOSED','Closed',30,0),
(364,'AGREEMENT_STATUS','DRAFT','Draft',10,1),
(365,'AGREEMENT_STATUS','ACTIVE','Active',20,1),
(366,'AGREEMENT_STATUS','ENDED','Ended',30,1),
(367,'AGREEMENT_SCOPE','POOL','Pool',10,1),
(368,'AGREEMENT_SCOPE','MARKETPLACE','Marketplace (one shop)',20,1),
(369,'AGREEMENT_SCOPE','PO','One purchase order',30,1),
(370,'WITHDRAWAL_STATUS','REQUESTED','Requested',10,1),
(371,'WITHDRAWAL_STATUS','APPROVED','Approved',20,1),
(372,'WITHDRAWAL_STATUS','REJECTED','Rejected',30,1),
(373,'WITHDRAWAL_STATUS','PAID','Paid',40,1),
(374,'FX_SOURCE','MANUAL','Typed',10,1),
(375,'FX_SOURCE','BANK','Bank line',20,1),
(376,'FX_SOURCE','PROVIDER','Rate provider',30,1),
(377,'MSG_CHANNEL','WHATSAPP','WhatsApp',10,1),
(378,'MSG_CHANNEL','EMAIL','E-mail',20,1),
(379,'MSG_CHANNEL','INAPP','In app',30,1),
(380,'MP_REPORT_ROLE','ORDERS','Orders',10,1),
(381,'MP_REPORT_ROLE','MONEY','Money',20,1),
(382,'MP_REPORT_ROLE','PAYOUTS','Payouts',30,1),
(383,'MP_REPORT_ROLE','REFUNDS','Refunds',40,1),
(384,'MP_REPORT_ROLE','INVENTORY','Inventory',50,1),
(385,'MP_REPORT_ROLE','ADS','Ads',60,1),
(386,'MP_REPORT_ROLE','HEALTH','Health',70,1),
(387,'MP_REPORT_ROLE','FULFILMENT','Fulfilment',80,1),
(388,'CHARGE_KIND','INSERTION','Insertion',10,1),
(389,'CHARGE_KIND','FVF','Fvf',20,1),
(390,'CHARGE_KIND','FVF_INAD','Fvf inad',30,1),
(391,'CHARGE_KIND','REGULATORY','Regulatory',40,1),
(392,'CHARGE_KIND','INTERNATIONAL','International',50,1),
(393,'CHARGE_KIND','AD_FEE','Ad fee',60,1),
(394,'CHARGE_KIND','BELOW_STD','Below std',70,1),
(395,'CHARGE_KIND','FEE_CREDIT','Fee credit',80,1),
(396,'CHARGE_KIND','REFUND','Refund',90,1),
(397,'CHARGE_KIND','POSTAGE','Postage',100,1),
(398,'CHARGE_KIND','SETTLEMENT_FEE','Settlement fee',110,1),
(399,'CHARGE_KIND','OTHER','Other',120,1),
(400,'METRIC_UNIT','PCT','%',10,1),
(401,'METRIC_UNIT','COUNT','Count',20,1),
(402,'METRIC_UNIT','AMOUNT','Amount',30,1),
(403,'METRIC_UNIT','DAYS','Days',40,1),
(404,'METRIC_UNIT','LEVEL','Level',50,1),
(405,'HEALTH_DIRECTION','HIGH','Bad when high',10,1),
(406,'HEALTH_DIRECTION','LOW','Bad when low',20,1),
(407,'PAYOUT_RULE','PER_SETTLEMENT','Per settlement',10,0),
(408,'PAYOUT_RULE','DAILY','Daily',20,0),
(409,'PAYOUT_RULE','WEEKLY','Weekly',30,0),
(410,'PAYOUT_RULE','MONTHLY','Monthly',40,0),
(411,'PAYOUT_RULE','ON_REQUEST','On request',50,0),
(412,'MONTH_TOTAL_RULE','SUM_LINES','Sum of lines',10,1),
(413,'MONTH_TOTAL_RULE','MASTER_ROW','Master row for multi-item orders',20,1),
(414,'VA_SHARE_BASIS','PROFIT_AFTER_FEE','Profit after fee',10,1),
(415,'VA_SHARE_BASIS','PROFIT_BEFORE_FEE','Profit before fee',20,1),
(416,'VA_SHARE_BASIS','SALES','Sales',30,1),
(417,'STATEMENT_FORMAT','CSV','Csv',10,1),
(418,'STATEMENT_FORMAT','XLSX','Xlsx',20,1),
(419,'STATEMENT_FORMAT','OFX','Ofx',30,1),
(420,'DATE_FORMAT','d/m/Y','D/m/y',10,1),
(421,'DATE_FORMAT','Y-m-d','Y-m-d',20,1),
(422,'DATE_FORMAT','d-M-y','D-m-y',30,1),
(423,'DATE_FORMAT','m/d/Y','M/d/y',40,1),
(424,'DATE_FORMAT','d.m.Y','D.m.y',50,1),
(425,'AMOUNT_RULE','ONE_SIGNED','One signed amount column',10,1),
(426,'AMOUNT_RULE','TWO_COLUMNS','Money out and money in columns',20,1),
(427,'PRINTER_KIND','THERMAL','Thermal label printer',10,1),
(428,'PRINTER_KIND','LASER','Laser / office printer',20,1),
(429,'PRINTER_CONNECTION','USB','Usb',10,1),
(430,'PRINTER_CONNECTION','NETWORK','Network',20,1),
(431,'PRINTER_CONNECTION','CLOUD','Cloud print',30,1),
(432,'LABEL_FORMAT','4X6_IN','4 x 6 inch',10,1),
(433,'LABEL_FORMAT','100X150_MM','100 x 150 mm',20,1),
(434,'LABEL_FORMAT','A6','A6',30,1),
(435,'LABEL_FORMAT','A4','A4',40,1),
(436,'LABEL_KIND','SHIPPING','Shipping label',10,1),
(437,'LABEL_KIND','FBA_CARTON','Inbound carton label',20,1),
(438,'LABEL_KIND','PRODUCT','Product label',30,1),
(439,'LABEL_KIND','BARCODE','Barcode label',40,1),
(440,'REPRINT_REASON','DAMAGED_LABEL','Label damaged',10,0),
(441,'REPRINT_REASON','PRINTER_JAM','Printer jam',20,1),
(442,'REPRINT_REASON','WRONG_PRINTER','Wrong printer',30,1),
(443,'REPRINT_REASON','CARRIER_CHANGED','Carrier changed',40,1),
(444,'REPRINT_REASON','LOST','Label lost',50,0),
(445,'REPRINT_REASON','OTHER','Other',60,1),
(446,'FBA_SHIPMENT_STATUS','DRAFT','Draft',10,1),
(447,'FBA_SHIPMENT_STATUS','READY','Ready',20,1),
(448,'FBA_SHIPMENT_STATUS','SHIPPED','Shipped',30,1),
(449,'FBA_SHIPMENT_STATUS','RECEIVING','Receiving',40,1),
(450,'FBA_SHIPMENT_STATUS','CLOSED','Closed',50,1),
(451,'CARTON_KIND','SINGLE','One SKU',10,1),
(452,'CARTON_KIND','MIXED','Mixed SKUs',20,1),
(453,'LOT_CONSUME_REASON','SALE','Sale',10,1),
(454,'LOT_CONSUME_REASON','RETURN','Return',20,1),
(455,'LOT_CONSUME_REASON','HISTORICAL_SOLD','Historical sold',30,1),
(456,'LOT_CONSUME_REASON','COST_CORRECTION','Cost correction',40,1),
(457,'CYCLE_ROW_STATUS','Confirmed','Confirmed (product line)',10,1),
(458,'CYCLE_ROW_STATUS','Fee','Fee',20,1),
(459,'CYCLE_ROW_STATUS','Cost','Cost',30,1),
(460,'CYCLE_ROW_STATUS','Sample','Sample',40,1),
(461,'CYCLE_ROW_STATUS','Draft','Draft',50,1),
(462,'TWO_FA_METHOD','TOTP','Authenticator app',10,1),
(463,'TWO_FA_METHOD','WHATSAPP','WhatsApp code',20,1),
(464,'TWO_FA_METHOD','EMAIL','E-mail code',30,1),
(465,'EXTRA_FIELD_TYPE','text','Text',10,1),
(466,'EXTRA_FIELD_TYPE','number','Number',20,1),
(467,'EXTRA_FIELD_TYPE','select','List',30,1),
(468,'EXTRA_FIELD_TYPE','check','Yes / No',40,1),
(469,'EXTRA_FIELD_TYPE','date','Date',50,1),
(470,'LISTING_STATUS','DRAFT','Draft',10,1),
(471,'LISTING_STATUS','ACTIVE','Active',20,1),
(472,'LISTING_STATUS','INACTIVE','Inactive',30,1),
(473,'LISTING_STATUS','SUPPRESSED','Suppressed',40,1),
(474,'LISTING_STATUS','STRANDED','Stranded',50,1),
(475,'LISTING_STATUS','BLOCKED','Blocked',60,1),
(476,'LISTING_STATUS','CLOSED','Closed',70,1),
(477,'LOCATION_KIND','ZONE','Zone (wall, rack, corner, area)',110,1),
(478,'LOCATION_KIND','BIN','Bin / shelf position',120,1),
(479,'LOCATION_KIND','UNASSIGNED','Unassigned (system)',130,1),
(480,'LOCATION_KIND','RECEIVING','Receiving (system, not sellable)',140,1),
(481,'LOCATION_KIND','DISPATCH','Dispatch / packing (system, not sellable)',150,1),
(482,'LOCATION_KIND','SHIPPED','Shipped – waiting for month confirm (system, not sellable)',160,1),
(483,'LOCATION_KIND','QUARANTINE','Quarantine (system, not sellable)',170,1),
(484,'LOCATION_KIND','MISSING','Missing – count losses (system, not sellable)',180,1),
(485,'LOCATION_KIND','WRITTEN_OFF','Written off (book only)',190,1),
(486,'WH_ZONE_TYPE','WALL','Wall',10,1),
(487,'WH_ZONE_TYPE','RACK','Rack / stand',20,1),
(488,'WH_ZONE_TYPE','CORNER','Corner',30,1),
(489,'WH_ZONE_TYPE','FLOOR','Floor',40,1),
(490,'WH_ZONE_TYPE','RECEIVING','Receiving area',50,1),
(491,'WH_ZONE_TYPE','DISPATCH','Dispatch / packing',60,1),
(492,'WH_ZONE_TYPE','RETURNS','Returns area',70,1),
(493,'WH_ZONE_TYPE','QUARANTINE','Quarantine',80,1),
(494,'WH_ZONE_TYPE','OTHER','Other',90,1),
(495,'WH_BIN_TYPE','SHELF','Shelf',10,1),
(496,'WH_BIN_TYPE','BIN','Bin',20,1),
(497,'WH_BIN_TYPE','RACK_SLOT','Rack slot',30,1),
(498,'WH_BIN_TYPE','FLOOR','Floor spot',40,1),
(499,'WH_BIN_TYPE','CORNER_BIN','Corner bin',50,1),
(500,'WH_BIN_TYPE','PALLET','Pallet place',60,1),
(501,'WH_SYSTEM_BIN','UNASSIGNED','Unassigned',10,1),
(502,'WH_SYSTEM_BIN','RECEIVING','Receiving',20,1),
(503,'WH_SYSTEM_BIN','DISPATCH','Dispatch',30,1),
(504,'WH_SYSTEM_BIN','SHIPPED','Shipped',40,1),
(505,'WH_SYSTEM_BIN','RETURNS','Returns',50,1),
(506,'WH_SYSTEM_BIN','QUARANTINE','Quarantine',60,1),
(507,'WH_SYSTEM_BIN','DAMAGED','Damaged',70,1),
(508,'WH_SYSTEM_BIN','MISSING','Missing',80,1),
(509,'WH_HOME_ROLE','NONE','Not a home',10,1),
(510,'WH_HOME_ROLE','PRIMARY','Primary home (pick bin)',20,1),
(511,'WH_HOME_ROLE','OVERFLOW','Overflow',30,1),
(512,'WH_STORAGE_MODE','FIXED','Fixed homes',10,1),
(513,'WH_STORAGE_MODE','HYBRID','Fixed pick bin + free reserve',20,1),
(514,'WH_STORAGE_MODE','CHAOTIC','Any free bin',30,1),
(515,'WH_MOVE_TYPE','RECEIVE','Receive',10,1),
(516,'WH_MOVE_TYPE','PUTAWAY','Put away',20,1),
(517,'WH_MOVE_TYPE','PICK','Pick',30,1),
(518,'WH_MOVE_TYPE','PUTBACK','Put back',40,1),
(519,'WH_MOVE_TYPE','SHIP','Ship (handover)',50,1),
(520,'WH_MOVE_TYPE','CONFIRM_OUT','Month confirm',60,1),
(521,'WH_MOVE_TYPE','RETURN_IN','Return in',70,1),
(522,'WH_MOVE_TYPE','TRANSFER','Bin transfer',80,1),
(523,'WH_MOVE_TYPE','REPLEN','Replenish',90,1),
(524,'WH_MOVE_TYPE','COUNT_LOSS','Count loss to Missing',100,1),
(525,'WH_MOVE_TYPE','COUNT_FOUND','Count found',110,1),
(526,'WH_MOVE_TYPE','ADJUST','Adjustment',120,1),
(527,'WH_MOVE_TYPE','WRITE_OFF','Write-off',130,1),
(528,'WH_MOVE_TYPE','FOUND_STOCK','Found stock',140,1),
(529,'WH_MOVE_TYPE','DAMAGE','Move to damaged',150,1),
(530,'WH_MOVE_TYPE','FBA_SEND','Inbound shipment out',160,1),
(531,'WH_MOVE_TYPE','PACK_MATERIAL','Packing material used',170,1),
(532,'WH_MOVE_TYPE','MIGRATION','Go-live migration',180,1),
(533,'WH_OWNER_RULE','POOL','Pool lots',10,1),
(534,'WH_OWNER_RULE','PRIVATE','Private owner lots',20,1),
(535,'WH_RESERVATION_STATUS','OPEN','Open',10,1),
(536,'WH_RESERVATION_STATUS','ALLOCATED','On a pick list',20,1),
(537,'WH_RESERVATION_STATUS','PICKED','Picked',30,1),
(538,'WH_RESERVATION_STATUS','SHIPPED','Shipped',40,1),
(539,'WH_RESERVATION_STATUS','RELEASED','Released',50,1),
(540,'WH_LINE_STATUS','OPEN','Open',10,1),
(541,'WH_LINE_STATUS','SHORT','Short',20,1),
(542,'WH_LINE_STATUS','ALLOCATED','On a pick list',30,1),
(543,'WH_LINE_STATUS','PICKED','Picked',40,1),
(544,'WH_LINE_STATUS','PACKED','Packed',50,1),
(545,'WH_LINE_STATUS','READY','Ready to dispatch',60,1),
(546,'WH_LINE_STATUS','SHIPPED','Handed over',70,1),
(547,'WH_LINE_STATUS','CANCELLED','Cancelled',80,1),
(548,'WH_TASK_TYPE','PICK','Pick',10,1),
(549,'WH_TASK_TYPE','PUTAWAY','Put away',20,1),
(550,'WH_TASK_TYPE','REPLEN','Replenish',30,1),
(551,'WH_TASK_TYPE','PUTBACK','Put back',40,1),
(552,'WH_TASK_TYPE','INBOUND','Inbound shipment pick',50,1),
(553,'WH_TASK_TYPE','MOVE','Move',60,1),
(554,'WH_PICK_MODE','SINGLE','One order',10,1),
(555,'WH_PICK_MODE','BATCH','Batch (several orders)',20,1),
(556,'WH_PICK_MODE','WAVE','Wave (cut-off window)',30,1),
(557,'WH_PICK_MODE','ZONE','By zone',40,1),
(558,'WH_PICK_STRATEGY','HOME_FIRST','Home first, then overflow',10,1),
(559,'WH_PICK_STRATEGY','LOWEST_QTY_FIRST','Lowest quantity bin first',20,1),
(560,'WH_PICK_STRATEGY','NEAREST_TO_PACKING','Nearest to packing',30,1),
(561,'WH_PICK_STRATEGY','FIFO_BIN','Oldest put-away first',40,1),
(562,'WH_TASK_STATUS','OPEN','Open',10,1),
(563,'WH_TASK_STATUS','IN_PROGRESS','In progress',20,1),
(564,'WH_TASK_STATUS','DONE','Done',30,1),
(565,'WH_TASK_STATUS','ABANDONED','Abandoned',40,1),
(566,'WH_TASK_STATUS','CANCELLED','Cancelled',50,1),
(567,'WH_LINE_TASK_STATUS','OPEN','Open',10,1),
(568,'WH_LINE_TASK_STATUS','DONE','Done',20,1),
(569,'WH_LINE_TASK_STATUS','SHORT','Short',30,1),
(570,'WH_LINE_TASK_STATUS','SKIPPED','Skipped',40,1),
(571,'WH_LINE_TASK_STATUS','RELEASED','Released',50,1),
(572,'WH_PARCEL_STATUS','OPEN','Open',10,1),
(573,'WH_PARCEL_STATUS','PACKED','Packed',20,1),
(574,'WH_PARCEL_STATUS','LABELLED','Labelled',30,1),
(575,'WH_PARCEL_STATUS','READY','Ready to dispatch',40,1),
(576,'WH_PARCEL_STATUS','HANDED_OVER','Handed over',50,1),
(577,'WH_PARCEL_STATUS','VOID','Label voided',60,1),
(578,'WH_PARCEL_STATUS','CANCELLED','Cancelled',70,1),
(579,'WH_PARCEL_STATUS','LOST_INVESTIGATING','Lost – investigating',80,1),
(580,'WH_PARCEL_STATUS','CLAIMED','Claim made',90,1),
(581,'WH_PARCEL_STATUS','CLOSED','Closed',100,1),
(582,'WH_CLAIM_STATUS','OPEN','Open',10,1),
(583,'WH_CLAIM_STATUS','PAID','Paid',20,1),
(584,'WH_CLAIM_STATUS','REJECTED','Rejected',30,1),
(585,'WH_HANDOVER_MODE','COLLECTION','Collection by the carrier',10,1),
(586,'WH_HANDOVER_MODE','DROP_OFF','Drop-off by us',20,1),
(587,'WH_HANDOVER_STATUS','OPEN','Open',10,1),
(588,'WH_HANDOVER_STATUS','CLOSED','Closed',20,1),
(589,'WH_HANDOVER_STATUS','REOPENED','Reopened',30,1),
(590,'WH_HANDOVER_RESULT','OK','OK',10,1),
(591,'WH_HANDOVER_RESULT','DUPLICATE','Already scanned',20,1),
(592,'WH_HANDOVER_RESULT','WRONG_CARRIER','Wrong carrier',30,1),
(593,'WH_HANDOVER_RESULT','NOT_READY','Not ready',40,1),
(594,'WH_HANDOVER_RESULT','UNKNOWN','Unknown code',50,1),
(595,'WH_HANDOVER_RESULT','UNSCANNED','Un-scanned (reopened)',60,1),
(596,'WH_LABEL_SOURCE','PDF_IMPORT','Import carrier label PDF',10,1),
(597,'WH_LABEL_SOURCE','CSV_EXPORT','Export carrier CSV, import labels',20,1),
(598,'WH_LABEL_SOURCE','API','Carrier API',30,1),
(599,'WH_RETURN_GRADE','RESELLABLE_NEW','Resellable – new / unopened',10,1),
(600,'WH_RETURN_GRADE','RESELLABLE_OPENED','Resellable – opened',20,1),
(601,'WH_RETURN_GRADE','DAMAGED','Damaged',30,1),
(602,'WH_RETURN_GRADE','SUPPLIER_FAULT','Supplier fault',40,1),
(603,'WH_RETURN_GRADE','WRONG_ITEM_RETURNED','Wrong item returned',50,1),
(604,'WH_RETURN_GRADE','MISSING_ITEM','Item missing (empty parcel)',60,1),
(605,'WH_INTAKE_STATUS','UNMATCHED','Unmatched',10,1),
(606,'WH_INTAKE_STATUS','MATCHED','Matched',20,1),
(607,'WH_INTAKE_STATUS','BOOKED','Booked',30,1),
(608,'WH_INTAKE_STATUS','FOUND_STOCK','Booked as found stock',40,1),
(609,'WH_INTAKE_STATUS','DISPOSED','Disposed',50,1),
(610,'WH_REFUND_REVIEW','NOT_NEEDED','Not needed',10,1),
(611,'WH_REFUND_REVIEW','OPEN','Open',20,1),
(612,'WH_REFUND_REVIEW','DONE','Done',30,1),
(613,'WH_COUNT_TYPE','FULL','Full site',10,1),
(614,'WH_COUNT_TYPE','ZONE','Zone',20,1),
(615,'WH_COUNT_TYPE','BIN','Bins',30,1),
(616,'WH_COUNT_TYPE','SKU','SKUs',40,1),
(617,'WH_COUNT_TYPE','CYCLE','Cycle (ABC)',50,1),
(618,'WH_COUNT_TYPE','TRIGGERED','Triggered',60,1),
(619,'WH_COUNT_TRIGGER','SHORT_PICK','Short pick',10,1),
(620,'WH_COUNT_TRIGGER','ZERO','Bin reached zero',20,1),
(621,'WH_COUNT_TRIGGER','NEGATIVE_ATTEMPT','Negative attempt',30,1),
(622,'WH_COUNT_TRIGGER','NO_ROOM','No room at putaway',40,1),
(623,'WH_COUNT_TRIGGER','SCHEDULE','Schedule',50,1),
(624,'WH_COUNT_TRIGGER','MANUAL','Manual',60,1),
(625,'WH_COUNT_LINE_STATUS','OPEN','Open',10,1),
(626,'WH_COUNT_LINE_STATUS','COUNTED','Counted',20,1),
(627,'WH_COUNT_LINE_STATUS','RECOUNT','Recount needed',30,1),
(628,'WH_COUNT_LINE_STATUS','DONE','Done',40,1),
(629,'WH_OVERRIDE_REASON','BIN_FULL','Suggested bin full',10,1),
(630,'WH_OVERRIDE_REASON','BIN_BLOCKED','Suggested bin blocked',20,1),
(631,'WH_OVERRIDE_REASON','CLOSER','Closer bin',30,1),
(632,'WH_OVERRIDE_REASON','OTHER','Other (note)',40,1),
(633,'WH_STOCK_SYNC','OFF','Off',10,1),
(634,'WH_STOCK_SYNC','FILE','File export',20,1),
(635,'WH_STOCK_SYNC','API','API',30,1),
(636,'WH_OOS_ACTION','ZERO','Set quantity 0',10,1),
(637,'WH_OOS_ACTION','END','End the listing',20,1),
(638,'WH_TRACKING_WHEN','ON_LABEL','When the label is made',10,1),
(639,'WH_TRACKING_WHEN','ON_HANDOVER','When handed over',20,1),
(640,'WH_UNSHIPPED_RULE','ASK','Ask (one-click backfill)',10,1),
(641,'WH_UNSHIPPED_RULE','AUTO','Backfill automatically',20,1),
(642,'WH_UNSHIPPED_RULE','BLOCK','Block Confirm',30,1),
(643,'WH_RESERVE_ON','INTAKE','At order intake',10,1),
(644,'WH_RESERVE_ON','PAID','When paid',20,1),
(645,'WH_RESERVE_ON','PICKLIST','At pick-list build',30,1),
(646,'WH_SERVICE_LEVEL','ECONOMY','Economy',10,1),
(647,'WH_SERVICE_LEVEL','STANDARD','Standard',20,1),
(648,'WH_SERVICE_LEVEL','EXPRESS','Express / next day',30,1),
(649,'WH_COUNTRY_GROUP','DOMESTIC','Domestic (company country)',10,1),
(650,'WH_COUNTRY_GROUP','REGION','Same region',20,1),
(651,'WH_COUNTRY_GROUP','WORLD','Rest of world',30,1),
(652,'LABEL_KIND','LOCATION','Shelf / bin label',50,1),
(653,'LABEL_KIND','PICK_SHEET','Pick sheet',60,1),
(654,'LABEL_KIND','PACKING_SLIP','Packing slip',70,1),
(655,'LABEL_KIND','MANIFEST','Handover manifest',80,1),
(656,'LABEL_STATUS','VOID','Label voided',60,1),
(657,'REPRINT_REASON','ADDRESS_CHANGED','Address changed',70,1),
(658,'REPRINT_REASON','LOCATION_CHANGED','Bin / home changed',80,1),
(659,'REPRINT_REASON','VOIDED_REPLACED','Voided – replacement',90,1),
(660,'PRINT_ENTITY','SALE','Sale line',10,1),
(661,'PRINT_ENTITY','FBA_CARTON','Inbound carton',20,1),
(662,'PRINT_ENTITY','LOCATION','Bin',30,1),
(663,'PRINT_ENTITY','PICK_TASK','Pick task',40,1),
(664,'PRINT_ENTITY','PARCEL','Parcel',50,1),
(665,'PRINT_ENTITY','HANDOVER','Handover session',60,1),
(666,'PRINT_ENTITY','PRODUCT','Product',70,1),
(667,'PRINT_TRIGGER','MANUAL','Manual',10,1),
(668,'PRINT_TRIGGER','AUTO_RECEIVE','On receive',20,1),
(669,'PRINT_TRIGGER','AUTO_SHELF','Shelf labels',30,1),
(670,'PRINT_TRIGGER','AUTO_PICKSHEET','On pick list',40,1),
(671,'PRINT_TRIGGER','AUTO_PACK','On pack',50,1),
(672,'PRINT_TRIGGER','AUTO_LABEL','On label ready',60,1),
(673,'PRINT_TRIGGER','AUTO_MANIFEST','On handover close',70,1),
(674,'PRINT_TRIGGER','BULK','Bulk',80,1),
(675,'PRINT_JOB_STATUS','QUEUED','Queued',10,1),
(676,'PRINT_JOB_STATUS','SENT','Sent',20,1),
(677,'PRINT_JOB_STATUS','PRINTED','Printed',30,1),
(678,'PRINT_JOB_STATUS','FAILED','Failed',40,1),
(679,'PRINT_JOB_STATUS','CANCELLED','Cancelled',50,1),
(680,'LABEL_FILE_KIND','PDF','PDF',10,1),
(681,'LABEL_FILE_KIND','ZPL','ZPL (raw printer language)',20,1),
(682,'LABEL_FILE_KIND','PNG','Image (PNG)',30,1),
(683,'LABEL_PDF_LAYOUT','ONE_PER_PAGE','One label per page',10,1),
(684,'LABEL_PDF_LAYOUT','A4_MULTI','Several labels on an A4 page',20,1),
(685,'LABEL_PDF_LAYOUT','ONE_PDF_PER_ORDER','One PDF file per order',30,1),
(686,'CUSTOMER_DATA_RULE','MATCH_ONLY','Address read only to match the order, never stored',10,1),
(687,'PAYMENT_PROVIDER_KIND','WALLET','Wallet / payment account',10,1),
(688,'PAYMENT_PROVIDER_KIND','CARD_ACQUIRER','Card acquirer',20,1),
(689,'PAYMENT_PROVIDER_KIND','FX_TRANSFER','Money transfer / FX account',30,1),
(690,'SIGN_RULE','AS_IS','As in the file',10,1),
(691,'SIGN_RULE','NEGATIVE_ONLY','Negative lines only',20,1),
(692,'SIGN_RULE','POSITIVE_ONLY','Positive lines only',30,1),
(693,'THREEPL_FEE_BASIS','PER_UNIT','Per unit',10,1),
(694,'THREEPL_FEE_BASIS','PER_ORDER','Per order',20,1),
(695,'THREEPL_FEE_BASIS','PER_CARTON','Per carton',30,1),
(696,'THREEPL_FEE_BASIS','PER_CBM_MONTH','Per cubic metre per month',40,1),
(697,'THREEPL_FEE_BASIS','FIXED_MONTH','Fixed per month',50,1),
(698,'DS_AUDIT_KIND','NONE','No audit',10,1),
(699,'DS_AUDIT_KIND','ORDER_HISTORY','Supplier order history file',20,1),
(700,'DS_AUDIT_KIND','HOLD_BUY','Hold-and-buy orders',30,1),
(701,'SEARCH_SYNONYM_KIND','ALIAS','Alias (other name)',10,1),
(702,'SEARCH_SYNONYM_KIND','ABBREVIATION','Abbreviation',20,1),
(703,'SEARCH_SYNONYM_KIND','SPELLING','Common misspelling',30,1),
(704,'SEARCH_SYNONYM_KIND','ROMAN_URDU','Roman Urdu word',40,1),
(705,'PACK_BOX_TYPE','RETAIL','Retail box',10,1),
(706,'PACK_BOX_TYPE','MAILER','Mailer box',20,1),
(707,'PACK_BOX_TYPE','INNER','Inner carton',30,1),
(708,'PACK_BOX_TYPE','PALLET','Pallet',50,1),
(709,'PACK_APPROVAL','DRAFT','Draft',10,1),
(710,'PACK_APPROVAL','SENT','Sent to printer for proof',20,1),
(711,'PACK_APPROVAL','APPROVED','Approved',30,1),
(712,'PACK_APPROVAL','REJECTED','Rejected',40,1),
(713,'ARTWORK_KIND','BOX','Box',10,1),
(714,'ARTWORK_KIND','INSERT','Insert card',20,1),
(715,'ARTWORK_KIND','LABEL','Label',30,1),
(716,'ARTWORK_KIND','MANUAL','Manual',40,1),
(717,'ARTWORK_KIND','CARTON_MARK','Carton marks',50,1),
(718,'ARTWORK_KIND','DIELINE','Dieline',60,1),
(719,'ARTWORK_STATUS','DRAFT','Draft',10,1),
(720,'ARTWORK_STATUS','IN_REVIEW','In review',20,1),
(721,'ARTWORK_STATUS','APPROVED','Approved',30,1),
(722,'ARTWORK_STATUS','SUPERSEDED','Replaced by a newer version',40,1),
(723,'LEGAL_TEXT_KIND','ORIGIN','Country of origin',10,1),
(724,'LEGAL_TEXT_KIND','CONFORMITY_MARK','Conformity mark',20,1),
(725,'LEGAL_TEXT_KIND','RESPONSIBLE_PERSON','Responsible person',30,1),
(726,'LEGAL_TEXT_KIND','MANUFACTURER','Manufacturer',40,1),
(727,'LEGAL_TEXT_KIND','IMPORTER','Importer',50,1),
(728,'LEGAL_TEXT_KIND','WARNING','Warning text',60,1),
(729,'LEGAL_TEXT_KIND','RECYCLING','Recycling text',70,1),
(730,'SHEET_PAPER','ROLL','Roll (one label)',10,1),
(731,'SHEET_PAPER','A4','A4 sheet',20,1),
(732,'SHEET_PAPER','LETTER','Letter sheet',30,1),
(733,'SESSION_END_REASON','LOGOUT','Signed out',10,1),
(734,'SESSION_END_REASON','TIMEOUT','Timed out',20,1),
(735,'SESSION_END_REASON','FORCED','Ended by an admin',30,1),
(736,'SESSION_END_REASON','IP_BLOCKED','Blocked by IP allowlist',40,1),
(737,'IP_SCOPE','ALL','Everyone',10,1),
(738,'IP_SCOPE','ROLE','One role',20,1),
(739,'IP_SCOPE','USER','One user',30,1),
(740,'SHARE_LINK_PURPOSE','PO_CONFIRM','Confirm the PO',10,1),
(741,'SHARE_LINK_PURPOSE','PRODUCTION_PHOTO','Send production photos',20,1),
(742,'SHARE_LINK_PURPOSE','SHIP_DATE','Give the ship date',30,1),
(743,'SHARE_LINK_PURPOSE','ARTWORK_DOWNLOAD','Download artwork and carton marks',40,1),
(744,'CUTOVER_STATUS','PLANNED','Planned',10,1),
(745,'CUTOVER_STATUS','COMPARING','Comparing old and new',20,1),
(746,'CUTOVER_STATUS','READY','Compare equal – ready to switch',30,1),
(747,'CUTOVER_STATUS','SWITCHED','Switched to the new tool',40,1),
(748,'CUTOVER_STATUS','ROLLED_BACK','Rolled back to the old screen',50,1),
(749,'QC_CHECK_RESULT','PASS','Pass',10,1),
(750,'QC_CHECK_RESULT','FAIL','Fail',20,1),
(751,'QC_CHECK_RESULT','NA','Not checked / not relevant',30,1),
(752,'CLOSE_CHECK_WAIVABLE','PAYOUT_VS_BANK','Payout vs bank',25,1),
(753,'PERIOD_STATUS','CHECKED','Checked (all checks green / waived / NA)',20,1),
(754,'PERIOD_STATUS','LOCKED','Locked',30,1),
(755,'LABEL_STATUS','NONE','No label yet',5,1),
(756,'LABEL_STATUS','READY','Label ready',15,1),
(757,'LABEL_STATUS','REPRINTED','Reprinted',35,1),
(758,'LABEL_STATUS','UPLOADED','Tracking uploaded',45,1),
(759,'REPRINT_REASON','LABEL_DAMAGED','Label damaged',10,1),
(760,'REPRINT_REASON','WRONG_SIZE','Wrong size',25,1),
(761,'REPRINT_REASON','LOST_BEFORE_DISPATCH','Lost before dispatch',50,1),
(762,'REPRINT_REASON','TEST_PRINT','Test print',55,1),
(763,'PAYOUT_RULE','PER_PAYOUT_LINE','One payout per payout line',60,1),
(764,'PAYOUT_RULE','NET_OF_RESERVE','Net of reserve',70,1),
(765,'PAYOUT_RULE','DAILY_SWEEP','Daily sweep',80,1),
(766,'ORDERS_CADENCE','MONTHLY','Monthly orders file',10,1),
(767,'ORDERS_CADENCE','DAILY','Daily only',20,1),
(768,'ORDERS_CADENCE','BOTH','Daily and monthly',30,1),
(769,'MONEY_CADENCE','PER_PAYOUT','Per payout',10,1),
(770,'MONEY_CADENCE','MONTHLY','Monthly',20,1),
(771,'MONEY_CADENCE','ROLLING','Rolling',30,1),
(772,'PAYOUT_TARGET','BANK','Bank account',10,1),
(773,'PAYOUT_TARGET','PROVIDER_WALLET','Payment-provider wallet',20,1),
(774,'RECORD_STATUS','DRAFT','Draft',10,1),
(775,'RECORD_STATUS','ACTIVE','Active',20,1),
(776,'CS_DATE_IS','REVIEW','Review date (filing due 14 days later)',10,1),
(777,'CS_DATE_IS','DUE','Filing due date',20,1),
(778,'PRINT_PATH','DRIVER_PDF','PDF through the printer driver',10,1),
(779,'PRINT_PATH','RAW','Raw printer commands',20,1),
(780,'PRINTER_KIND','INKJET','Inkjet',30,1),
(781,'PRINTER_CONNECTION','BLUETOOTH','Bluetooth',40,1),
(782,'PRINTER_CONNECTION','LAN','LAN cable',50,1),
(783,'PRINTER_CONNECTION','WIFI','Wi-Fi',60,1),
(784,'LABEL_ORIENTATION','PORTRAIT','Portrait',10,1),
(785,'LABEL_ORIENTATION','LANDSCAPE','Landscape',20,1),
(786,'LABEL_TEMPLATE_SOURCE','CARRIER_PDF','Carrier PDF (crop / scale)',10,1),
(787,'LABEL_TEMPLATE_SOURCE','SYSTEM','Built by the tool',20,1),
(788,'LABEL_SCALE_RULE','ACTUAL','Actual size',10,1),
(789,'LABEL_SCALE_RULE','FIT','Fit to label',20,1),
(790,'LABEL_CROP_RULE','FULL_PAGE','Full page',10,1),
(791,'LABEL_CROP_RULE','TOP_HALF','Top half',20,1),
(792,'LABEL_CROP_RULE','BOTTOM_HALF','Bottom half',30,1),
(793,'LABEL_CROP_RULE','QUARTER_1','Quarter 1 (top left)',40,1),
(794,'LABEL_CROP_RULE','QUARTER_2','Quarter 2 (top right)',50,1),
(795,'LABEL_CROP_RULE','QUARTER_3','Quarter 3 (bottom left)',60,1),
(796,'LABEL_CROP_RULE','QUARTER_4','Quarter 4 (bottom right)',70,1),
(797,'FEE_BUCKET_SIGN','COST','Cost (adds to selling costs)',10,1),
(798,'FEE_BUCKET_SIGN','CREDIT','Credit (taken off selling costs)',20,1),
(799,'CHARGE_KIND','POSTAGE_LABEL','Postage labels bought on the marketplace',130,1),
(800,'CHARGE_KIND','CLAIM_FEE_CREDIT','Claim fee credit (outside selling costs)',140,1),
(801,'CHARGE_KIND','FULL_REFUND','Full refund',150,1),
(802,'CHARGE_KIND','PARTIAL_REFUND','Partial refund',160,1),
(803,'CHARGE_KIND','HALF_REFUND','Half refund',170,1),
(804,'CHARGE_KIND','REFUND_OUTSIDE','Refund outside the marketplace',180,1),
(805,'CHARGE_KIND','MARKETPLACE_FEE','Marketplace fee',190,1),
(806,'CHARGE_KIND','CLAIM','Claim',200,1),
(807,'CHARGE_KIND','REIMBURSEMENT','Reimbursement',210,1),
(808,'CHARGE_KIND','CHARGEBACK','Chargeback',220,1),
(809,'WH_BOX','MAILER_S','Mailer bag small',10,1),
(810,'WH_BOX','MAILER_L','Mailer bag large',20,1),
(811,'WH_BOX','BOX_S','Box small',30,1),
(812,'WH_BOX','BOX_M','Box medium',40,1),
(813,'WH_BOX','BOX_L','Box large',50,1),
(814,'PRINTER_KIND','LABEL_INKJET','Colour label printer',40,1),
(815,'PRINTER_KIND','OTHER','Other / new technology',90,1),
(816,'PRINT_PATH','PRINT_AGENT','Print helper on the packing PC',30,1),
(817,'PRINT_PATH','IPP','Network PDF printing (IPP / AirPrint)',40,1),
(818,'PRINT_PATH','CLOUD','Cloud print service',50,1),
(819,'PRINTER_COLOUR','MONO','Black only',10,1),
(820,'PRINTER_COLOUR','COLOUR','Colour',20,1),
(821,'PRINTER_STATUS','ACTIVE','Active',10,1),
(822,'PRINTER_STATUS','SPARE','Spare',20,1),
(823,'PRINTER_STATUS','REPAIR','In repair',30,1),
(824,'PRINTER_STATUS','RETIRED','Retired',40,1),
(825,'MEDIA_KIND','ROLL','Roll',10,1),
(826,'MEDIA_KIND','FANFOLD','Fanfold stack',20,1),
(827,'MEDIA_KIND','SHEET','Sheet (A4 / Letter)',30,1),
(828,'MEDIA_SENSOR','GAP','Gap between labels',10,1),
(829,'MEDIA_SENSOR','BLACK_MARK','Black mark',20,1),
(830,'MEDIA_SENSOR','CONTINUOUS','Continuous',30,1),
(831,'PRINT_ROUTE_SCOPE','USER','One user',10,1),
(832,'PRINT_ROUTE_SCOPE','WORKSTATION','One computer / packing desk',20,1),
(833,'PRINT_ROUTE_SCOPE','LOCATION','One site / warehouse',30,1),
(834,'PRINT_ROUTE_SCOPE','COMPANY','Whole company',40,1),
(835,'PRINT_JOB_KIND','SHIPPING_LABEL','Shipping label',10,1),
(836,'PRINT_JOB_KIND','RETURN_LABEL','Return label',20,1),
(837,'PRINT_JOB_KIND','PRODUCT_LABEL','Product / SKU label',30,1),
(838,'PRINT_JOB_KIND','BARCODE_LABEL','Barcode label',35,1),
(839,'PRINT_JOB_KIND','FBA_CARTON_LABEL','Carton label',40,1),
(840,'PRINT_JOB_KIND','LOCATION_LABEL','Shelf / bin label',50,1),
(841,'PRINT_JOB_KIND','PICK_LIST','Pick list',60,1),
(842,'PRINT_JOB_KIND','PACKING_SLIP','Packing slip',70,1),
(843,'PRINT_JOB_KIND','INVOICE','Invoice / credit note',80,1),
(844,'PRINT_JOB_KIND','STATEMENT','Statement',90,1),
(845,'PRINT_JOB_KIND','REPORT','Report / list',100,1),
(846,'PRINT_JOB_KIND','A4_LABEL_SHEET','Labels on an A4 sheet',110,1),
(847,'PRINT_JOB_KIND','HANDOVER_SHEET','Courier handover sheet',120,1),
(851,'BRAND_REG_STATUS','NONE','Not registered',10,1),
(852,'BRAND_REG_STATUS','APPLIED','Applied',20,1),
(853,'BRAND_REG_STATUS','REGISTERED','Registered',30,1),
(854,'BRAND_REG_STATUS','REJECTED','Rejected',40,1),
(855,'BRAND_REG_STATUS','LAPSED','Lapsed',50,1),
(856,'SUPPLIER_RATING','A','A – preferred',10,1),
(857,'SUPPLIER_RATING','B','B – good',20,1),
(858,'SUPPLIER_RATING','C','C – use with care',30,1),
(859,'SUPPLIER_RATING','D','D – do not use',40,1),
(860,'CASE_CHANNEL','MESSAGE','Marketplace message',10,1),
(861,'CASE_CHANNEL','EMAIL','E-mail',20,1),
(862,'CASE_CHANNEL','PHONE','Phone',30,1),
(863,'CASE_CHANNEL','RETURN_REQUEST','Return request',40,1),
(864,'CASE_CHANNEL','FEEDBACK','Feedback / review',50,1),
(865,'CASE_CHANNEL','CLAIM','Claim / dispute',60,1),
(866,'CASE_CHANNEL','OTHER','Other',70,1),
(867,'TIMEZONE','Asia/Kolkata','Asia/Kolkata',80,1),
(868,'TIMEZONE','Asia/Dhaka','Asia/Dhaka',90,1),
(869,'PROFIT_VIEW','LOT','Profit by lot (stock owner)',10,1),
(870,'PROFIT_VIEW','BATCH','Profit by batch (purchase order / shipment)',20,1),
(871,'PROFIT_VIEW','INVESTOR','Profit by investor',30,1),
(872,'PROFIT_VIEW','SHOP','Profit by shop',40,1),
(873,'PROFIT_VIEW','SKU','Profit by product (SKU)',50,1),
(874,'PROFIT_VIEW','PARTNER','Profit by partner / VA share',60,1),
(875,'STACKING_RULE','SPLIT_UNITS','Own-lot profit on own units + share only on the other units (recommended)',10,1),
(876,'STACKING_RULE','NONE','No share on a PO where the party owns any lot',20,1),
(877,'STACKING_RULE','CAPPED','Both, but the share is capped at a % of the PO profit',30,1),
(878,'STACKING_RULE','FULL_STACK','Both in full (needs owner approval)',40,1),
(879,'DS_MARGIN_PCT','0','0 % (only stop losses)',10,1),
(880,'DS_MARGIN_PCT','5','5 %',20,1),
(881,'DS_MARGIN_PCT','10','10 % (suggested)',30,1),
(882,'DS_MARGIN_PCT','12','12 %',40,1),
(883,'DS_MARGIN_PCT','15','15 %',50,1),
(884,'DS_MARGIN_PCT','20','20 %',60,1),
(885,'DS_MARGIN_PCT','25','25 %',70,1),
(886,'DS_MARGIN_PCT','30','30 %',80,1),
(887,'RESEARCH_STATUS','IDEA','Idea',10,1),
(888,'RESEARCH_STATUS','RESEARCHING','Researching',20,1),
(889,'RESEARCH_STATUS','SHORTLIST','Shortlist',30,1),
(890,'RESEARCH_STATUS','GO','Go – start sourcing',40,1),
(891,'RESEARCH_STATUS','NO_GO','No go',50,1),
(892,'RESEARCH_STATUS','PARKED','Parked',60,1),
(893,'LEVEL_3','LOW','Low',10,1),
(894,'LEVEL_3','MEDIUM','Medium',20,1),
(895,'LEVEL_3','HIGH','High',30,1),
(896,'RESEARCH_RISK','BATTERY','Has a battery',10,1),
(897,'RESEARCH_RISK','DANGEROUS_GOODS','Dangerous goods',20,1),
(898,'RESEARCH_RISK','IP_BRAND','Brand / patent risk',30,1),
(899,'RESEARCH_RISK','CERTIFICATE_NEEDED','Certificate needed',40,1),
(900,'RESEARCH_RISK','FRAGILE','Fragile',50,1),
(901,'RESEARCH_RISK','OVERSIZE','Oversize',60,1),
(902,'RESEARCH_RISK','AGE_RESTRICTED','Age restricted',70,1),
(903,'FEE_BUCKET_SIGN','AS_FILE','Signed amount as in the file',30,1);
DROP TABLE IF EXISTS `sys_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_fields` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form_key` varchar(60) NOT NULL,
  `field_key` varchar(60) NOT NULL,
  `label` varchar(120) NOT NULL,
  `help_text` varchar(500) DEFAULT NULL,
  `field_type` enum('text','textarea','number','money','percent','date','datetime','select','multiselect','party','product','listing','location','shop','company','currency','link','file','checkbox','email','phone') NOT NULL,
  `list_source` varchar(120) DEFAULT NULL,
  `depends_on` varchar(60) DEFAULT NULL,
  `default_rule` varchar(120) DEFAULT NULL,
  `required_mode` enum('NO','FULL','QUICK_AND_FULL') NOT NULL DEFAULT 'NO',
  `f_in_quick` tinyint(4) NOT NULL DEFAULT 0,
  `f_in_filter` tinyint(4) NOT NULL DEFAULT 1,
  `f_in_search` tinyint(4) NOT NULL DEFAULT 0,
  `f_in_import` tinyint(4) NOT NULL DEFAULT 0,
  `f_add_new` enum('NONE','ANY','PENDING','ADMIN') NOT NULL DEFAULT 'NONE',
  `validation` varchar(200) DEFAULT NULL,
  `group_name` varchar(60) DEFAULT NULL,
  `sort_no` int(11) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `section_no` smallint(6) NOT NULL DEFAULT 1,
  `field_no` smallint(6) NOT NULL DEFAULT 0,
  `add_form_key` varchar(60) DEFAULT NULL,
  `visible_to` enum('ALL','OWNER','OWNER_HR','FINANCE','OWNER_PARTNER') NOT NULL DEFAULT 'ALL',
  `f_readonly` tinyint(4) NOT NULL DEFAULT 0,
  `auto_correct` enum('none','lower','upper','trim','sku') NOT NULL DEFAULT 'none',
  `step_value` decimal(12,4) DEFAULT NULL,
  `min_value` decimal(14,4) DEFAULT NULL,
  `max_value` decimal(14,4) DEFAULT NULL,
  `unit_label` varchar(20) DEFAULT NULL,
  `f_inline_edit` tinyint(4) NOT NULL DEFAULT 0,
  `f_bulk_edit` tinyint(4) NOT NULL DEFAULT 0,
  `suggestion_sources` varchar(60) NOT NULL DEFAULT 'recent,linked,history',
  `column_name` varchar(64) DEFAULT NULL,
  `table_name` varchar(80) DEFAULT NULL,
  `required_if` varchar(200) DEFAULT NULL,
  `show_if` varchar(200) DEFAULT NULL,
  `f_secret` tinyint(4) NOT NULL DEFAULT 0,
  `legacy_form_key` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_field` (`form_key`,`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_form_loads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_form_loads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `loaded_by` int(11) DEFAULT NULL,
  `file_name` varchar(200) NOT NULL,
  `forms_count` int(11) NOT NULL,
  `fields_count` int(11) NOT NULL,
  `added` int(11) NOT NULL DEFAULT 0,
  `changed` int(11) NOT NULL DEFAULT 0,
  `removed` int(11) NOT NULL DEFAULT 0,
  `errors_json` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_form_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_form_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form_key` varchar(60) NOT NULL,
  `section_no` smallint(6) NOT NULL,
  `title` varchar(120) NOT NULL,
  `visible_to` enum('ALL','OWNER','OWNER_HR','FINANCE','OWNER_PARTNER') NOT NULL DEFAULT 'ALL',
  `html_renderer` varchar(60) DEFAULT NULL,
  `help` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sec` (`form_key`,`section_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_forms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_forms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form_key` varchar(60) NOT NULL,
  `module` varchar(60) NOT NULL,
  `department` varchar(60) NOT NULL,
  `title` varchar(120) NOT NULL,
  `save_handler` varchar(120) NOT NULL,
  `open_url` varchar(200) NOT NULL,
  `f_in_create` tinyint(4) NOT NULL DEFAULT 0,
  `f_quick` tinyint(4) NOT NULL DEFAULT 0,
  `required_action` enum('VIEW','ADD','EDIT','DELETE','APPROVE','EXPORT','REVEAL','IMPORT','LOCK') NOT NULL DEFAULT 'ADD',
  `fk_id_module` int(11) DEFAULT NULL,
  `sort_no` int(11) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `table_name` varchar(64) DEFAULT NULL,
  `dept` varchar(30) DEFAULT NULL,
  `open_route` varchar(120) DEFAULT NULL,
  `label_expr` varchar(120) DEFAULT NULL,
  `f_has_quick` tinyint(4) NOT NULL DEFAULT 1,
  `f_is_master` tinyint(4) NOT NULL DEFAULT 0,
  `f_approval_required` tinyint(4) NOT NULL DEFAULT 0,
  `extends_form_key` varchar(60) DEFAULT NULL,
  `help` varchar(300) DEFAULT NULL,
  `legacy_form_key` varchar(60) DEFAULT NULL,
  `master_mode` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `form_key` (`form_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `sys_forms` VALUES
(1,'src_cycle','Sourcing','Buying','Sourcing cycle (full)','Src_cycle_service::save','t_src_cycle/view/{id}',1,0,'ADD',NULL,301,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(2,'quick_buy','Sourcing','Buying','Quick buy (local purchase)','Src_cycle_service::saveQuick','t_src_cycle/view/{id}',1,1,'ADD',NULL,302,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(3,'old_entry','Sourcing','Buying','Old purchase entry','Src_cycle_service::saveOldEntry','t_src_cycle/view/{id}',1,1,'ADD',NULL,303,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(4,'src_rfq','Sourcing','Buying','RFQ / quotation','Src_rfq_service::save','t_src_rfq/view/{id}',1,0,'ADD',NULL,305,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(5,'src_payment','Sourcing','Buying','Supplier payment','Src_payment_service::save','t_src_payment/view/{id}',1,0,'ADD',NULL,308,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(6,'expense','Finance','Money','Expense','Finance_service::saveExpense','finance_partners/expense/{id}',1,1,'ADD',NULL,903,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(7,'bank_import','Import','Money','Bank statement (import)','Import_center::start','import/start?type=BANK_STATEMENT',1,0,'ADD',NULL,902,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(8,'capital_call','Finance','Money','Capital call','Finance_service::saveCapitalCall','finance_partners/capital_call/{id}',1,0,'ADD',NULL,904,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(9,'withdrawal','Finance','Money','Withdrawal request','Finance_service::saveWithdrawal','finance_partners/withdrawal/{id}',1,0,'ADD',NULL,905,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(10,'product','Products','Products & Listings','Product','Product_service::save','t_product_vendor/edit/{id}',1,1,'ADD',NULL,501,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(11,'listing','Products','Products & Listings','Listing','Listing_service::save','listings/view/{id}',1,1,'ADD',NULL,502,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(12,'stock_transfer','Stock','Receiving & Warehouse','Stock transfer','Stock_service::saveTransfer','stock/transfer/{id}',1,0,'ADD',NULL,408,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(13,'stock_adjust','Stock','Receiving & Warehouse','Stock adjustment','Stock_service::saveAdjustment','stock/movement/{id}',1,0,'ADD',NULL,407,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(14,'ownership_transfer','Stock','Receiving & Warehouse','Ownership transfer','Stock_service::saveOwnershipTransfer','stock/ownership/{id}',1,0,'ADD',NULL,410,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(15,'stock_count','Stock','Receiving & Warehouse','Stock count','Stock_service::saveCount','stock/count/{id}',1,0,'ADD',NULL,411,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(16,'sale_manual','Sales','Selling','Sale (manual)','Sales_service::saveManual','t_sales/view/{id}',1,1,'ADD',NULL,601,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(17,'return','Sales','Returns & Customers','Return','Sales_service::saveReturn','t_sales/view/{id}',1,1,'ADD',NULL,801,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(18,'customer_case','Sales','Returns & Customers','Customer service case','Cs_service::save','customer_service/view/{id}',1,0,'ADD',NULL,802,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(19,'import','Import','Import Center','Import a file','Import_center::start','import/start',1,0,'ADD',NULL,1501,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(20,'party','Accounts','People & Accounts','Person or company','Party_service::save','t_account/view/{id}',1,1,'ADD',NULL,1201,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(21,'subscription','Accounts','People & Accounts','Subscription / tool account','Account_kit_service::saveSubscription','account_kit/view/{id}',1,0,'ADD',NULL,1205,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(22,'task','Tasks','Work','Task','TaskService::create','t_tasks/view/{id}',1,1,'ADD',NULL,1301,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(23,'document','Documents','Documents','Document (link or file)','Documents_service::save','documents/view/{id}',1,1,'ADD',NULL,1401,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(24,'reference_value','Setup','Setup','List value (Reference Register)','Reference_service::addValue','reference_register/view/{id}',1,1,'ADD',NULL,1721,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(25,'src_research','Sourcing','Research','Product research / idea','Src_research_service::save','src_research/view/{id}',1,1,'ADD',NULL,201,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(26,'t_src_direct','Buying','Buying','Direct PO (repeat order)','Src_cycle_service::saveDirect','t_src_direct/view/{id}',1,1,'ADD',NULL,304,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(27,'t_src_samples','Buying','Buying','Sample','Src_cycle_service::saveSample','t_src_samples/view/{id}',1,1,'ADD',NULL,306,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(28,'t_src_po','Buying','Buying','Purchase order','Src_cycle_service::savePo','t_src_po/view/{id}',1,1,'ADD',NULL,307,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(29,'t_src_production','Buying','Buying','Production update','Src_cycle_service::saveProduction','t_src_production/view/{id}',1,1,'ADD',NULL,309,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(30,'t_src_qc','Buying','Buying','QC inspection','Src_cycle_service::saveQc','t_src_qc/view/{id}',1,1,'ADD',NULL,310,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(31,'t_src_allocations','Buying','Buying','Allocation (stock plan)','Src_cycle_service::saveAllocation','t_src_allocations/view/{id}',1,1,'ADD',NULL,311,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(32,'t_src_platform_alloc','Buying','Buying','Platform allocation','Src_cycle_service::savePlatformAllocation','t_src_platform_alloc/view/{id}',1,1,'ADD',NULL,312,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(33,'t_src_shipments','Buying','Buying','Shipment','Src_cycle_service::saveShipment','t_src_shipments/view/{id}',1,1,'ADD',NULL,313,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(34,'t_src_freight','Buying','Buying','Freight booking','Src_cycle_service::saveFreight','t_src_freight/view/{id}',1,1,'ADD',NULL,314,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(35,'t_src_freight_costs','Buying','Buying','Freight cost','Src_cycle_service::saveFreightCost','t_src_freight_costs/view/{id}',1,1,'ADD',NULL,315,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(36,'t_src_customs','Buying','Buying','Customs entry','Src_cycle_service::saveCustoms','t_src_customs/view/{id}',1,1,'ADD',NULL,316,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(37,'supplier','Buying','Buying','Supplier / forwarder','Party_service::saveSupplier','supplier/view/{id}',1,1,'ADD',NULL,317,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(38,'t_src_forwarders','Buying','Buying','Forwarder','Party_service::saveForwarder','t_src_forwarders/view/{id}',1,1,'ADD',NULL,318,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(39,'t_src_grn','Receiving & Warehouse','Receiving & Warehouse','GRN (goods received)','Src_cycle_service::saveGrn','t_src_grn/view/{id}',1,1,'ADD',NULL,401,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(40,'putaway','Receiving & Warehouse','Receiving & Warehouse','Putaway (Work screen)','Work_screen::open','work?tab=putaway',1,0,'ADD',NULL,402,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(41,'location','Receiving & Warehouse','Receiving & Warehouse','Location / bin','Warehouse_service::saveLocation','location/view/{id}',1,1,'ADD',NULL,403,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(42,'warehouse','Receiving & Warehouse','Receiving & Warehouse','Warehouse site','Warehouse_service::saveWarehouse','warehouse/view/{id}',1,1,'ADD',NULL,404,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(43,'warehouse_zone','Receiving & Warehouse','Receiving & Warehouse','Warehouse zone','Warehouse_service::saveWarehouseZone','warehouse_zone/view/{id}',1,1,'ADD',NULL,405,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(44,'sku_home','Receiving & Warehouse','Receiving & Warehouse','SKU home (fixed bin)','Warehouse_service::saveSkuHome','sku_home/view/{id}',1,1,'ADD',NULL,406,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(45,'t_src_transfers','Receiving & Warehouse','Receiving & Warehouse','Sourcing transfer','Stock_service::saveSourcingTransfer','t_src_transfers/view/{id}',1,1,'ADD',NULL,409,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(46,'icc','Receiving & Warehouse','Receiving & Warehouse','ICC inputs (inventory cycle)','Icc_engine::saveInputs','icc/view/{id}',1,1,'ADD',NULL,412,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(47,'icc_holiday','Receiving & Warehouse','Receiving & Warehouse','ICC holiday','Icc_engine::saveIccHoliday','icc_holiday/view/{id}',1,1,'ADD',NULL,413,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(48,'icc_freight_rate','Receiving & Warehouse','Receiving & Warehouse','ICC freight rate','Icc_engine::saveIccFreightRate','icc_freight_rate/view/{id}',1,0,'ADD',NULL,414,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(49,'product_packaging','Receiving & Warehouse','Receiving & Warehouse','Packaging (per SKU and pack)','Product_service::saveProductPackaging','product_packaging/view/{id}',1,1,'ADD',NULL,415,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(50,'wh_box_size','Receiving & Warehouse','Receiving & Warehouse','Packing box size','Warehouse_service::saveBoxSize','wh_box_size/view/{id}',1,0,'ADD',NULL,416,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(51,'brand','Products & Listings','Products & Listings','Brand','Product_service::saveBrand','brand/view/{id}',1,1,'ADD',NULL,503,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(52,'daily_order_entry','Selling','Selling','Daily order (fulfilment intake)','Sales_service::saveDailyOrderEntry','daily_order_entry/view/{id}',1,1,'ADD',NULL,602,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(53,'drp_platform','Selling','Selling','Dropship platform','Ds_match_service::savePlatform','drp_platform/view/{id}',1,1,'ADD',NULL,603,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(54,'t_shop_feedback','Selling','Selling','Account health entry','Sales_service::saveHealthEntry','t_shop_feedback/view/{id}',1,1,'ADD',NULL,604,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(55,'t_branch_reminders','Selling','Selling','Shop monthly reminder','Sales_service::saveShopReminder','t_branch_reminders/view/{id}',1,1,'ADD',NULL,605,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(56,'fba_shipment','Dispatch','Dispatch','FC / 3PL inbound shipment','Stock_service::saveFbaShipment','fba_shipment/view/{id}',1,1,'ADD',NULL,701,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(57,'fin_bank_account','Money','Money','Company bank account','Finance_service::saveBankAccount','fin_bank_account/view/{id}',1,1,'ADD',NULL,901,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(58,'fin_fx_rate','Money','Money','Exchange rate','Finance_service::saveFxRate','fin_fx_rate/view/{id}',1,1,'ADD',NULL,906,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(59,'fin_commitment','Money','Money','Money commitment (payable)','Finance_service::saveCommitment','fin_commitment/view/{id}',1,1,'ADD',NULL,907,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(60,'fin_loan','Money','Money','Loan','Finance_service::saveLoan','fin_loan/view/{id}',1,0,'ADD',NULL,908,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(61,'fin_budget','Money','Money','Budget line','Finance_service::saveBudget','fin_budget/view/{id}',1,0,'ADD',NULL,909,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(62,'fin_alert_rule','Money','Money','Finance alert rule','Finance_service::saveAlertRule','fin_alert_rule/view/{id}',1,0,'ADD',NULL,910,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(63,'t_branch_profit_routes','Month Close & Audit','Month Close & Audit','Profit route (shop × month)','Audit_engine::saveProfitRoute','t_branch_profit_routes/view/{id}',1,1,'ADD',NULL,1001,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(64,'aud_expense_rule','Month Close & Audit','Month Close & Audit','Shared expense rule','Audit_engine::saveExpenseRule','aud_expense_rule/view/{id}',1,0,'ADD',NULL,1002,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(65,'invoice_document','Month Close & Audit','Month Close & Audit','Invoice / credit note','Inv_engine::saveInvoice','invoice_document/view/{id}',1,0,'ADD',NULL,1003,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(66,'acc_obligation','Month Close & Audit','Month Close & Audit','Filing item (obligation)','Compliance_service::saveObligation','acc_obligation/view/{id}',1,1,'ADD',NULL,1004,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(67,'acc_obligation_type','Month Close & Audit','Month Close & Audit','Filing type','Compliance_service::saveObligationType','acc_obligation_type/view/{id}',1,0,'ADD',NULL,1005,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(68,'acc_document','Month Close & Audit','Month Close & Audit','Compliance document (proof)','Compliance_service::saveDocument','acc_document/view/{id}',1,0,'ADD',NULL,1006,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(69,'acc_access','Month Close & Audit','Month Close & Audit','Compliance access row','Compliance_service::saveAccess','acc_access/view/{id}',1,1,'ADD',NULL,1007,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(70,'tax_period','Month Close & Audit','Month Close & Audit','Tax period','Tax_service::saveTaxPeriod','tax_period/view/{id}',1,1,'ADD',NULL,1008,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(71,'tax_entry','Month Close & Audit','Month Close & Audit','Tax entry','Tax_service::saveTaxEntry','tax_entry/view/{id}',1,1,'ADD',NULL,1009,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(72,'tax_line_item','Month Close & Audit','Month Close & Audit','Tax line item','Tax_service::saveTaxLineItem','tax_line_item/view/{id}',1,1,'ADD',NULL,1010,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(73,'tax_category','Month Close & Audit','Month Close & Audit','Tax category','Tax_service::saveTaxCategory','tax_category/view/{id}',1,1,'ADD',NULL,1011,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(74,'tax_rate','Month Close & Audit','Month Close & Audit','Corporation tax rate','Tax_service::saveTaxRate','tax_rate/view/{id}',1,0,'ADD',NULL,1012,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(75,'fin_opening_balance','Month Close & Audit','Month Close & Audit','Opening balance','Finance_service::saveOpeningBalance','fin_opening_balance/view/{id}',1,0,'ADD',NULL,1013,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(76,'fin_investor_agreement','Partners & Investors','Partners & Investors','Partner agreement','Finance_service::saveInvestorAgreement','fin_investor_agreement/view/{id}',1,1,'ADD',NULL,1101,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(77,'t_inv_settings','Partners & Investors','Partners & Investors','Profit split party','Inv_engine::saveSplitParty','t_inv_settings/view/{id}',1,1,'ADD',NULL,1102,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(78,'inv_profit_split','Partners & Investors','Partners & Investors','Profit split rule','Inv_engine::saveSplitRule','inv_profit_split/view/{id}',1,1,'ADD',NULL,1103,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(79,'fin_partner_user','Partners & Investors','Partners & Investors','Partner portal login','Finance_service::savePartnerUser','fin_partner_user/view/{id}',1,0,'ADD',NULL,1104,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(80,'company','People & Accounts','People & Accounts','Company (enterprise)','Party_service::saveCompany','company/view/{id}',1,1,'ADD',NULL,1202,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(81,'shop','People & Accounts','People & Accounts','Shop / seller account','Party_service::saveShop','shop/view/{id}',1,1,'ADD',NULL,1203,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(82,'t_mobile_device','People & Accounts','People & Accounts','Phone set (device)','Account_kit_service::saveMobileDevice','t_mobile_device/view/{id}',1,1,'ADD',NULL,1204,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(83,'sys_due_source','People & Accounts','People & Accounts','Due-date source','Due_engine::saveDueSource','sys_due_source/view/{id}',1,0,'ADD',NULL,1206,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(84,'task_category','Work','Work','Task category (SLA)','TaskService::saveTaskCategory','task_category/view/{id}',1,1,'ADD',NULL,1302,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(85,'task_template','Work','Work','Task template','TaskService::saveTaskTemplate','task_template/view/{id}',1,1,'ADD',NULL,1303,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(86,'task_absence','Work','Work','Absence and cover','TaskService::saveTaskAbsence','task_absence/view/{id}',1,0,'ADD',NULL,1304,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(87,'task_delegation','Work','Work','Approval delegation','TaskService::saveTaskDelegation','task_delegation/view/{id}',1,0,'ADD',NULL,1305,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(88,'wa_template','Work','Work','WhatsApp template','Wa_service::saveTemplate','wa_template/view/{id}',1,0,'ADD',NULL,1306,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(89,'playbook_step','Work','Work','Step playbook','Help_service::savePlaybookStep','playbook_step/view/{id}',1,1,'ADD',NULL,1307,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(90,'imp_drive_folder','Import Center','Import Center','Watched Drive folder','Import_center::saveDriveFolder','imp_drive_folder/view/{id}',1,0,'ADD',NULL,1502,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(91,'imp_report_type','Import Center','Import Center','Import report type','Import_center::saveReportType','imp_report_type/view/{id}',1,1,'ADD',NULL,1503,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(92,'imp_mapping','Import Center','Import Center','Import column mapping','Import_center::saveMapping','imp_mapping/view/{id}',1,1,'ADD',NULL,1504,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(93,'api_connection','Import Center','Import Center','API connection','Api_sync::saveConnection','api_connection/view/{id}',1,1,'ADD',NULL,1505,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(94,'mp_profile','Setup','Setup','Marketplace profile','Profile_service::saveMpProfile','mp_profile/view/{id}',1,1,'ADD',NULL,1701,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(95,'marketplace','Setup','Setup','Marketplace','Profile_service::saveMarketplace','marketplace/view/{id}',1,1,'ADD',NULL,1702,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(96,'carrier','Setup','Setup','Carrier service','Profile_service::saveCarrierService','carrier/view/{id}',1,1,'ADD',NULL,1703,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(97,'t_carriers','Setup','Setup','Carrier (carrier profile)','Profile_service::saveCarrierProfile','t_carriers/view/{id}',1,1,'ADD',NULL,1704,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(98,'t_carrier_packages','Setup','Setup','Carrier package','Profile_service::saveCarrierPackage','t_carrier_packages/view/{id}',1,1,'ADD',NULL,1705,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(99,'t_carrier_pkg_svc','Setup','Setup','Carrier package × service','Profile_service::saveCarrierPkgSvc','t_carrier_pkg_svc/view/{id}',1,1,'ADD',NULL,1706,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(100,'car_preset','Setup','Setup','Carrier preset group','Profile_service::saveCarPreset','car_preset/view/{id}',1,1,'ADD',NULL,1707,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(101,'t_packings','Setup','Setup','Packing (material)','Profile_service::savePacking','t_packings/view/{id}',1,1,'ADD',NULL,1708,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(102,'carrier_service_map','Setup','Setup','Carrier service map row','Profile_service::saveCarrierServiceMap','carrier_service_map/view/{id}',1,1,'ADD',NULL,1709,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(103,'carrier_service_rule','Setup','Setup','Carrier service rule','Profile_service::saveCarrierServiceRule','carrier_service_rule/view/{id}',1,0,'ADD',NULL,1710,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(104,'bank_statement_profile','Setup','Setup','Bank statement profile','Profile_service::saveBankStatementProfile','bank_statement_profile/view/{id}',1,1,'ADD',NULL,1711,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(105,'payment_provider_profile','Setup','Setup','Payment-provider profile','Profile_service::savePaymentProviderProfile','payment_provider_profile/view/{id}',1,1,'ADD',NULL,1712,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(106,'threepl_profile','Setup','Setup','3PL profile','Profile_service::saveThreeplProfile','threepl_profile/view/{id}',1,1,'ADD',NULL,1713,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(107,'dropship_supplier_profile','Setup','Setup','Dropship-supplier profile','Profile_service::saveDropshipSupplierProfile','dropship_supplier_profile/view/{id}',1,1,'ADD',NULL,1714,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(108,'printer_profile','Setup','Setup','Printer','Print_service::savePrinterProfile','printer_profile/view/{id}',1,1,'ADD',NULL,1715,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(109,'label_media','Setup','Setup','Label media','Print_service::saveLabelMedia','label_media/view/{id}',1,1,'ADD',NULL,1716,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(110,'print_route','Setup','Setup','Print route','Print_service::savePrintRoute','print_route/view/{id}',1,1,'ADD',NULL,1717,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(111,'label_template','Setup','Setup','Label template','Print_service::saveLabelTemplate','label_template/view/{id}',1,1,'ADD',NULL,1718,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(112,'label_sheet_layout','Setup','Setup','Label sheet layout','Print_service::saveLabelSheetLayout','label_sheet_layout/view/{id}',1,1,'ADD',NULL,1719,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(113,'label_regulation_profile','Setup','Setup','Label regulation profile','Label_service::saveLabelRegulationProfile','label_regulation_profile/view/{id}',1,1,'ADD',NULL,1720,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(114,'cat_reference','Setup','Setup','Reference list (heading)','Reference_service::saveReference','cat_reference/view/{id}',1,1,'ADD',NULL,1722,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(115,'cat_country','Setup','Setup','Country','Catalog_service::save(\'cat_country\')','cat_country/view/{id}',1,1,'ADD',NULL,1723,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(116,'cat_currency','Setup','Setup','Currency','Catalog_service::save(\'cat_currency\')','cat_currency/view/{id}',1,1,'ADD',NULL,1724,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(117,'cat_ports','Setup','Setup','Port','Catalog_service::save(\'cat_ports\')','cat_ports/view/{id}',1,1,'ADD',NULL,1725,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(118,'cat_incoterm','Setup','Setup','Incoterm','Catalog_service::save(\'cat_incoterm\')','cat_incoterm/view/{id}',1,1,'ADD',NULL,1726,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(119,'cat_payment_terms','Setup','Setup','Payment term','Catalog_service::save(\'cat_payment_terms\')','cat_payment_terms/view/{id}',1,1,'ADD',NULL,1727,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(120,'cat_banks','Setup','Setup','Bank (catalog)','Catalog_service::save(\'cat_banks\')','cat_banks/view/{id}',1,1,'ADD',NULL,1728,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(121,'card_categories','Setup','Setup','Card category','Catalog_service::save(\'card_categories\')','card_categories/view/{id}',1,1,'ADD',NULL,1729,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(122,'card_providers','Setup','Setup','Card provider','Catalog_service::save(\'cc_providers\')','card_providers/view/{id}',1,1,'ADD',NULL,1730,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(123,'card_types','Setup','Setup','Card type','Catalog_service::save(\'card_types\')','card_types/view/{id}',1,1,'ADD',NULL,1731,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(124,'cat_paypal_questions','Setup','Setup','PayPal setup question','Catalog_service::save(\'paypal_questions\')','cat_paypal_questions/view/{id}',1,1,'ADD',NULL,1732,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(125,'cat_social_media','Setup','Setup','Social media platform','Catalog_service::save(\'cat_social_media\')','cat_social_media/view/{id}',1,1,'ADD',NULL,1733,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(126,'cat_mobile_network','Setup','Setup','Mobile network','Catalog_service::save(\'cat_mobile_network\')','cat_mobile_network/view/{id}',1,1,'ADD',NULL,1734,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(127,'cat_ip_burgers','Setup','Setup','IP provider account','Catalog_service::save(\'cat_ip_burgers\')','cat_ip_burgers/view/{id}',1,1,'ADD',NULL,1735,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(128,'cat_pc','Setup','Setup','PC','Catalog_service::save(\'cat_tbl_pc\')','cat_pc/view/{id}',1,1,'ADD',NULL,1736,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(129,'cat_photo_id','Setup','Setup','Photo-ID type','Catalog_service::save(\'cat_photo_ids\')','cat_photo_id/view/{id}',1,1,'ADD',NULL,1737,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(130,'cat_doc_type','Setup','Setup','Document type','Catalog_service::save(\'cat_doc_types\')','cat_doc_type/view/{id}',1,1,'ADD',NULL,1738,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(131,'cat_subscription_items','Setup','Setup','Subscription item','Catalog_service::save(\'cat_subscription_items\')','cat_subscription_items/view/{id}',1,1,'ADD',NULL,1739,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(132,'cat_product_condition','Setup','Setup','Product condition','Catalog_service::save(\'cat_product_conditions\')','cat_product_condition/view/{id}',1,1,'ADD',NULL,1740,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(133,'cat_product_type','Setup','Setup','Product type (department)','Catalog_service::save(\'cat_product_type\')','cat_product_type/view/{id}',1,1,'ADD',NULL,1741,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(134,'cat_product_trends','Setup','Setup','Product trend','Catalog_service::save(\'cat_product_trends\')','cat_product_trends/view/{id}',1,1,'ADD',NULL,1742,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(135,'cat_tags','Setup','Setup','Tag','Catalog_service::save(\'cat_tags\')','cat_tags/view/{id}',1,1,'ADD',NULL,1743,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(136,'price_change_reason','Setup','Setup','Price change reason','Catalog_service::save(\'cat_price_change_reasons\')','price_change_reason/view/{id}',1,1,'ADD',NULL,1744,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(137,'sys_security_question','Setup','Setup','Security question','Catalog_service::save(\'sys_security_questions\')','sys_security_question/view/{id}',1,1,'ADD',NULL,1745,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(138,'cat_account_type','Setup','Setup','Role','Rights_service::saveRole','cat_account_type/view/{id}',1,1,'ADD',NULL,1746,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(139,'sys_approval_rule','Setup','Setup','Approval rule (maker-checker)','Approval_engine::saveApprovalRule','sys_approval_rule/view/{id}',1,0,'ADD',NULL,1747,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(140,'sys_approval_limit','Setup','Setup','Approval limit per role','Approval_engine::saveApprovalLimit','sys_approval_limit/view/{id}',1,0,'ADD',NULL,1748,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(141,'ip_allowlist','Setup','Setup','IP allow-list row','Security_service::saveIpAllowlist','ip_allowlist/view/{id}',1,1,'ADD',NULL,1749,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(142,'non_working_day','Setup','Setup','Closed day (working calendar)','Settings_service::saveNonWorkingDay','non_working_day/view/{id}',1,1,'ADD',NULL,1750,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL),
(143,'search_synonym','Setup','Setup','Search synonym','Search_service::saveSearchSynonym','search_synonym/view/{id}',1,1,'ADD',NULL,1751,1,NULL,NULL,NULL,NULL,1,0,0,NULL,NULL,NULL,NULL);
DROP TABLE IF EXISTS `sys_home_summary`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_home_summary` (
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_branch` int(11) NOT NULL DEFAULT 0,
  `metric_key` varchar(60) NOT NULL,
  `metric_value` decimal(16,2) DEFAULT NULL,
  `metric_text` varchar(255) DEFAULT NULL,
  `as_of` datetime NOT NULL,
  PRIMARY KEY (`fk_id_ent`,`fk_id_branch`,`metric_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_ip_allowlist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_ip_allowlist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `scope` varchar(8) NOT NULL DEFAULT 'ALL',
  `role_code` varchar(20) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_cidr` varchar(50) NOT NULL,
  `source_note` varchar(150) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_ipa` (`fk_id_ent`,`f_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='IP allowlist, used when setting IP_ALLOWLIST_MODE is WARN or ENFORCE';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_job_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_job_runs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `job_code` varchar(60) NOT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `run_status` varchar(8) NOT NULL DEFAULT 'RUNNING',
  `items_done` int(11) NOT NULL DEFAULT 0,
  `messages_queued` int(11) NOT NULL DEFAULT 0,
  `error_text` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_job` (`job_code`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Every scheduled job run (R-ACC-22, R-G_CORE-110)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_learned_choices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_learned_choices` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `context` varchar(60) NOT NULL,
  `input_key` varchar(190) NOT NULL,
  `chosen_value` varchar(190) NOT NULL,
  `times` int(11) NOT NULL DEFAULT 1,
  `last_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_choice` (`fk_id_ent`,`context`,`input_key`,`chosen_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_list_columns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_list_columns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `list_key` varchar(60) NOT NULL,
  `col_no` smallint(6) NOT NULL,
  `column_name` varchar(64) NOT NULL,
  `label` varchar(120) NOT NULL,
  `col_type` enum('text','number','money','date','status','link','bool') NOT NULL DEFAULT 'text',
  `f_filter` tinyint(4) NOT NULL DEFAULT 1,
  `f_default_visible` tinyint(4) NOT NULL DEFAULT 1,
  `f_inline_edit` tinyint(4) NOT NULL DEFAULT 0,
  `field_key` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_col` (`list_key`,`col_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_login_attempts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `login_id` varchar(150) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `f_success` tinyint(4) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_login` (`login_id`,`created_at`),
  KEY `ix_ip` (`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_merge_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_merge_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity` enum('PARTY','PRODUCT','LOCATION','LIST_ITEM','SALE') NOT NULL,
  `source_table` varchar(64) NOT NULL,
  `source_id` bigint(20) NOT NULL,
  `master_table` varchar(64) NOT NULL,
  `master_id` bigint(20) NOT NULL,
  `match_score` decimal(5,2) DEFAULT NULL,
  `status` enum('SUGGESTED','CONFIRMED','REJECTED','UNDONE') NOT NULL DEFAULT 'SUGGESTED',
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_src` (`source_table`,`source_id`),
  KEY `ix_master` (`master_table`,`master_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_controller` int(11) NOT NULL,
  `method` varchar(80) NOT NULL,
  `action` enum('VIEW','ADD','EDIT','DELETE','APPROVE','EXPORT','REVEAL','IMPORT','LOCK') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perm` (`fk_id_controller`,`method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_playbooks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_playbooks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `workflow_code` varchar(40) NOT NULL,
  `step_code` varchar(40) NOT NULL,
  `title` varchar(150) NOT NULL,
  `checklist_text` text NOT NULL,
  `video_url` varchar(500) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pb` (`fk_id_ent`,`workflow_code`,`step_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Step playbooks (R-G_CORE-107)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_recent_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_recent_values` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `form_key` varchar(60) NOT NULL,
  `field_key` varchar(60) NOT NULL,
  `value` varchar(255) NOT NULL,
  `used_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `k_user_field` (`fk_id_account`,`form_key`,`field_key`,`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_role_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_role_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_role` int(11) NOT NULL,
  `fk_id_module` int(11) NOT NULL,
  `action` enum('VIEW','ADD','EDIT','DELETE','APPROVE','EXPORT','REVEAL','IMPORT','LOCK') NOT NULL,
  `f_allowed` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ra` (`fk_id_role`,`fk_id_module`,`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rule_code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `module` varchar(60) NOT NULL,
  `trigger_event` varchar(60) DEFAULT NULL,
  `level` enum('URGENT','NORMAL','INFO') NOT NULL DEFAULT 'NORMAL',
  `assign_to` varchar(60) NOT NULL,
  `due_days` int(11) NOT NULL DEFAULT 1,
  `handler` varchar(120) NOT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `priority` varchar(10) NOT NULL DEFAULT 'NORMAL',
  `category_id` int(11) DEFAULT NULL,
  `owner_rule` varchar(200) DEFAULT NULL,
  `dedupe_key` varchar(150) DEFAULT NULL,
  `auto_close` varchar(200) DEFAULT NULL,
  `fix_link` varchar(200) DEFAULT NULL,
  `money_field` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rule_code` (`rule_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `sys_rules` VALUES
(1,'STK_REORDER','Reorder now (ICC)','Stock',NULL,'NORMAL','role:MANAGER',2,'Rules_stock::reorder',1,'NORMAL',6,'MANAGER fewest open','STK_REORDER:<product>','PO / cycle created or ICC OK','ICC › product',NULL),
(2,'STK_DIFF','Ledger and product stock differ','Stock',NULL,'URGENT','role:ADMIN',1,'Rules_stock::ledgerDiff',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(3,'STK_FBA_DIFF','FBA report differs from FBA location','Stock','IMPORT_SAVED','NORMAL','role:STOCK',2,'Rules_stock::fbaDiff',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(4,'STK_DAMAGED','Damaged units â€“ claim','Stock','GRN_SAVED','NORMAL','role:SOURCING',3,'Rules_stock::damagedClaim',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(5,'SRC_PAY_DUE','Supplier payment due','Sourcing',NULL,'NORMAL','role:FINANCE',0,'Rules_src::paymentDue',1,'HIGH',5,'FINANCE fewest open (PO owner copied)','SRC_PAY_DUE:<term>','term Paid','Sourcing › Payment schedule','term remaining (base)'),
(6,'SRC_QC_FAIL','QC failed â€“ corrective action','Sourcing','QC_SAVED','URGENT','record.created_by',2,'Rules_src::qcFail',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(7,'SRC_SHIP_LAT','Shipment late','Sourcing',NULL,'NORMAL','role:SOURCING',1,'Rules_src::shipLate',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(8,'SRC_LANDED','GRN without final landed cost','Sourcing',NULL,'NORMAL','role:SOURCING',3,'Rules_src::landedMissing',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(9,'IMP_PROBLEM','Import batch applied with errors / set-aside rows','Imports',NULL,'NORMAL','role:MANAGER',1,'Rules_imports::problem',1,'NORMAL',9,'batch uploader → category default','IMP_PROBLEM:<batch>','batch undone or re-run clean','Import Center › batch',NULL),
(10,'IMP_MISSING','Monthly report missing','Import',NULL,'NORMAL','shop.listing_manager',3,'Rules_imp::missingFiles',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(11,'SAL_NO_COST','Sale without cost','Sales',NULL,'NORMAL','role:FINANCE',2,'Rules_sales::noCost',1,'HIGH',2,'PARTNER (own stock) / shop VA (dropship line) → category default','SAL_NO_COST:<shop>:<YYYY-MM>','no line without cost','Sales › Lines without cost',NULL),
(12,'SAL_ZERO','Sale line with status Loss_Sale or zero value','Sales',NULL,'NORMAL','role:VA',2,'Rules_sales::zero',1,'LOW',2,'shop VA','SAL_ZERO:<sale line>','line reviewed (accepted with note)','Sales › line',NULL),
(13,'SAL_DS_MARG','Dropship margin negative','Sales',NULL,'NORMAL','shop.listing_manager',0,'Rules_sales::dsMargin',1,'NORMAL',8,'shop VA → shop TL','SAL_DS_MARG:<sale line>','margin ≥ minimum or accepted with note','Sales › line › Dropship cost',NULL),
(14,'LST_STATUS','Listing suppressed / stranded','Products','IMPORT_APPLIED','NORMAL','listing.manager',1,'Rules_listing::status',1,'NORMAL',3,'TL of the shop','LST_STATUS:<listing>','status consistent with stock','Listings › listing',NULL),
(15,'LST_PRICE','Price below cost + fees + margin','Products','LISTING_SAVED','URGENT','listing.manager',0,'Rules_listing::priceGuard',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(16,'FIN_UNMATCH','Bank lines not matched','Finance',NULL,'NORMAL','role:FINANCE',2,'Rules_fin::unmatched',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(17,'FIN_FX','Amount without FX rate','Finance',NULL,'URGENT','role:FINANCE',1,'Rules_fin::fxMissing',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(18,'FIN_APPROVE','Approval waiting','Finance','APPROVAL_REQUESTED','NORMAL','approver',1,'Rules_fin::approval',1,'HIGH',10,'approver by sys_approval_limits → OWNER (never the maker)','FIN_APPROVE:<approval>','approved or rejected','Approvals','request amount (base)'),
(19,'AUD_RED','Audit check red','Audit','AUDIT_CHECKED','NORMAL','check.owner',2,'Rules_aud::redCheck',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(20,'AUD_CLOSE','Month not closed by day 10','Audit',NULL,'URGENT','role:OWNER',0,'Rules_aud::lateClose',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(21,'DUE_SOON','Renewal / payment / filing due','Accounts',NULL,'NORMAL','due.owner',0,'Due_engine::run',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(22,'PTN_QUERY','Partner asked a question','Partners','STATEMENT_QUERY','URGENT','role:OWNER',1,'Rules_ptn::query',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(23,'PTN_WDRAW','Withdrawal request','Partners','WITHDRAWAL_REQUESTED','URGENT','role:OWNER',1,'Rules_ptn::withdrawal',0,'NORMAL',NULL,NULL,NULL,NULL,NULL,NULL),
(24,'SYS_ERROR','New system error','System',NULL,'NORMAL','role:ADMIN',0,'Rules_sys::errors',1,'HIGH',16,'ADMIN fewest open','SYS_ERROR:<source>:<YYYY-MM-DD>','same source runs OK','System › Health',NULL),
(25,'SAL_UNMATCHED','Sale lines not matched','Sales','IMPORT_APPLIED','NORMAL','record.created_by',1,'Rules_sales::unmatched',1,'NORMAL',2,'batch uploader → shop VA → category default → VA fewest open','SAL_UNMATCHED:<batch>','unmatched count of the batch = 0','Sales › Import batch › Unmatched tab',NULL),
(26,'SAL_MONTH_MISSING','Monthly report missing','Sales',NULL,'NORMAL','shop.listing_manager',1,'Rules_sales::monthMissing',1,'HIGH',2,'shop VA → shop TL → category default → TL fewest open','SAL_MONTH_MISSING:<shop>:<YYYY-MM>','a batch for shop × month exists','Import Center (type + shop + month filled)',NULL),
(27,'SAL_PRICE_MISMATCH','Sold at a different price','Sales','IMPORT_APPLIED','NORMAL','shop.listing_manager',3,'Rules_sales::priceMismatch',1,'NORMAL',2,'shop VA → category default → VA fewest open','SAL_PRICE_MISMATCH:<listing>:<batch>','period corrected or line accepted','Sales › Batch › Price check',NULL),
(28,'SAL_STOCK_SHORT','Stock short at month confirm','Sales',NULL,'NORMAL','role:STOCK',1,'Rules_sales::stockShort',1,'HIGH',6,'STOCK role fewest open → WAREHOUSE','SAL_STOCK_SHORT:<sku>:<batch>','a confirm of that batch succeeds','Stock › Product ledger',NULL),
(29,'SAL_RETURN_EXPECTED','Return expected','Sales',NULL,'NORMAL','record.created_by',7,'Rules_sales::returnExpected',1,'NORMAL',2,'shop VA → WAREHOUSE fewest open','SAL_RETURN_EXPECTED:<order>:<sku>','return booked RECEIVED or case closed with reason','Sales › Returns › record',NULL),
(30,'SAL_HUB_MISMATCH','A month fee bucket differs from the typed marketplace hub figure by more than HUB_TOLERANCE (profiles with hub_totals_av','Sales',NULL,'NORMAL','role:TL',2,'Rules_sales::hubMismatch',1,'NORMAL',2,'shop TL → category default → TL fewest open','SAL_HUB_MISMATCH:<shop>:<YYYY-MM>:<bucket>','difference ≤ tolerance or check waived','Sales › Month totals',NULL),
(34,'SAL_MISSING','Orders audit: order in the marketplace file but not in sales','Sales',NULL,'NORMAL','role:VA',2,'Rules_sales::missing',1,'HIGH',2,'shop VA → shop TL','SAL_MISSING:<run>:<order>:<sku>','line added or explained','Sales › Orders audit › Missing',NULL),
(35,'SAL_EXTRA','Orders audit: sale line of the month not in the marketplace file','Sales',NULL,'NORMAL','role:VA',2,'Rules_sales::extra',1,'NORMAL',2,'shop VA → shop TL','SAL_EXTRA:<run>:<order>:<sku>','line removed or explained','Sales › Orders audit › Extra',NULL),
(36,'SAL_DIFF','Orders audit: qty, rate, total or item note differ between file and sales','Sales',NULL,'NORMAL','role:VA',2,'Rules_sales::diff',1,'NORMAL',2,'shop VA → shop TL','SAL_DIFF:<run>:<order>:<sku>','values equal or difference accepted','Sales › Orders audit › Different',NULL),
(37,'SAL_REFUND_OPEN','Refund proposals open','Sales','IMPORT_APPLIED','NORMAL','shop.listing_manager',1,'Rules_sales::refundOpen',1,'NORMAL',2,'shop VA → category default','SAL_REFUND_OPEN:<shop>:<YYYY-MM>','no open proposal','Sales › Refund proposals',NULL),
(38,'SAL_DAILY_OPEN','Daily-only shop: FULFILMENT lines still without price after Close daily orders (Confirm blocked)','Sales',NULL,'NORMAL','role:VA',2,'Rules_sales::dailyOpen',1,'HIGH',2,'shop VA → shop TL','SAL_DAILY_OPEN:<shop>:<YYYY-MM>','every line of the month priced','Sales › Daily orders › Close daily orders',NULL),
(39,'DS_HOLD_UNUSED','Hold order not used after DS_HOLD_DAYS (default 14, owner to confirm)','Dropship',NULL,'NORMAL','role:VA',2,'Rules_dropship::holdUnused',1,'NORMAL',8,'shop VA','DS_HOLD_UNUSED:<hold order>','hold used or cancelled','Dropship › Hold orders',NULL),
(40,'DS_UNMATCHED','Dropship supplier rows not matched to a sale after the sales month is confirmed','Dropship',NULL,'NORMAL','role:VA',2,'Rules_dropship::unmatched',1,'NORMAL',8,'shop VA → shop TL','DS_UNMATCHED:<shop>:<YYYY-MM>','unmatched count = 0','Dropship › list (Unmatched)',NULL),
(41,'PRICE_HISTORY_MISSING','Sale lines used current / product price because no price period covers the order date','Pricing',NULL,'NORMAL','role:TL',2,'Rules_pricing::historyMissing',1,'NORMAL',4,'TL fewest open','PRICE_HISTORY_MISSING:<sku>:<mp_profile>','a period covers the date','Products › Price periods',NULL),
(42,'PRICE_BELOW_FLOOR','Listing price below price floor on save','Pricing',NULL,'NORMAL','role:TL',2,'Rules_pricing::belowFloor',1,'HIGH',4,'TL fewest open → PARTNER','PRICE_BELOW_FLOOR:<product>:<mp_profile>','price ≥ floor','Products › Marketplace prices',NULL),
(43,'PRD_FEE_MISSING','Marketplace price row with 3PL / FC channel and no packing or storage fee','Pricing',NULL,'NORMAL','role:TL',2,'Rules_pricing::feeMissing',1,'NORMAL',4,'TL fewest open','PRD_FEE_MISSING:<product>:<mp_profile>','fee filled (0 allowed when typed)','Products › Marketplace prices',NULL),
(44,'LST_CREATE','Sellable product with stock not listed in a shop it is visible to','Listings',NULL,'NORMAL','role:TL',3,'Rules_listings::create',1,'LOW',3,'TL of the shop','LST_CREATE:<shop>:<product>','ACTIVE listing exists or product hidden for the shop','Listings › Not listed per shop',NULL),
(46,'CAR_RATE_MISSING','Carrier preset saved with rate 0','Labels',NULL,'NORMAL','role:TL',0,'Rules_labels::rateMissing',1,'NORMAL',7,'TL fewest open → ADMIN','CAR_RATE_MISSING:<preset>','rate > 0','Setup › Carrier presets',NULL),
(47,'CAR_SERVICE_UNMAPPED','Marketplace service text on a sale line / label has no carrier_service_map row','Labels',NULL,'NORMAL','role:WAREHOUSE',0,'Rules_labels::serviceUnmapped',1,'NORMAL',7,'WAREHOUSE → TL','CAR_SERVICE_UNMAPPED:<mp_profile>:<service text>','mapping row added','Setup › Carrier service map',NULL),
(48,'LBL_NOT_PRINTED','FULFILMENT line with label IMPORTED and not printed by the profile cut-off time','Labels',NULL,'NORMAL','role:WAREHOUSE',0,'Rules_labels::notPrinted',1,'HIGH',7,'WAREHOUSE fewest open','LBL_NOT_PRINTED:<shop>:<date>','all labels of that shop × date printed','Labels › Print queue',NULL),
(49,'LBL_TRACKING_UPLOAD','Printed label whose tracking number is not uploaded to the marketplace by the cut-off','Labels',NULL,'NORMAL','role:VA',0,'Rules_labels::trackingUpload',1,'HIGH',7,'shop VA → WAREHOUSE','LBL_TRACKING_UPLOAD:<shop>:<date>','label_status UPLOADED for all','Labels › Tracking upload',NULL),
(51,'IMP_WAITING','Import waiting for a decision','Import',NULL,'NORMAL','record.created_by',1,'Rules_imp::waiting',1,'LOW',9,'batch uploader → category default','IMP_WAITING:<batch>','batch applied or discarded','Import Center › batch',NULL),
(52,'INV_OVERDUE','Invoice due date passed and balance > 0','Invoicing',NULL,'NORMAL','role:FINANCE',3,'Rules_invoicing::overdue',1,'HIGH',11,'issuer party\'s owner → FINANCE fewest open','INV_OVERDUE:<invoice>','balance = 0 or invoice cancelled','Invoicing › invoice','invoice balance (base)'),
(53,'INV_PARTY_INCOMPLETE','Invoice party details incomplete','Finance',NULL,'NORMAL','role:FINANCE',2,'Rules_inv::partyIncomplete',1,'NORMAL',11,'FINANCE fewest open','INV_PARTY_INCOMPLETE:<party>','required print details filled','Invoicing › Party details',NULL),
(54,'HEALTH_ALERT','Shop health metric turns red (profile health metric thresholds)','ShopHealth',NULL,'NORMAL','role:TL',2,'Rules_shophealth::alert',1,'HIGH',14,'shop TL','HEALTH_ALERT:<shop>:<metric>:<YYYY-MM>','metric green','Shops › Health',NULL),
(55,'DQ_ITEM_NOTE','Sale line with an item note, not local and no vendor','DataQuality',NULL,'NORMAL','role:VA',7,'Rules_dataquality::itemNote',1,'LOW',15,'shop VA','DQ_ITEM_NOTE:<sale line>','vendor set or note cleared','Sales › line',NULL),
(56,'DQ_PARENT_INACTIVE','Active child variant whose parent is inactive','DataQuality',NULL,'NORMAL','role:TL',7,'Rules_dataquality::parentInactive',1,'LOW',15,'TL fewest open','DQ_PARENT_INACTIVE:<child product>','parent active or child inactive','Products › product',NULL),
(57,'SYS_API_SYNC','API sync failing','System',NULL,'NORMAL','role:ADMIN',1,'Rules_sys::apiSync',1,'HIGH',16,'ADMIN fewest open','SYS_API_SYNC:<api connection>','next sync OK','System › API connections',NULL),
(59,'SYS_WA_FAILING','WhatsApp messages failing','System',NULL,'NORMAL','role:ADMIN',0,'Rules_sys::waFailing',1,'HIGH',16,'ADMIN fewest open','SYS_WA_FAILING:<provider>','a send succeeds','System › WhatsApp',NULL),
(61,'SRC_QC_FAILED','QC failed – corrective action','Sourcing','STEP_DONE','NORMAL','role:MANAGER',2,'Rules_src::qcFailed',1,'HIGH',5,'QC owner → MANAGER fewest open','SRC_QC_FAILED:<qc code>','corrective action filled','Sourcing › QC',NULL),
(62,'SRC_SHIP_ARRIVING','Shipment arriving soon','Sourcing',NULL,'NORMAL','role:WAREHOUSE',1,'Rules_src::shipArriving',1,'NORMAL',5,'WAREHOUSE fewest open','SRC_SHIP_ARRIVING:<shipment>','arrival entered or ETA passed','Sourcing › Shipments',NULL),
(63,'SRC_SHIP_OVERDUE','Shipment overdue','Sourcing',NULL,'NORMAL','role:MANAGER',1,'Rules_src::shipOverdue',1,'HIGH',5,'shipment owner → MANAGER fewest open','SRC_SHIP_OVERDUE:<shipment>','arrival entered or new ETA ≥ today','Sourcing › Shipments',NULL),
(64,'SRC_GRN_SHORT','GRN short or damaged – claim','Sourcing','GRN_POSTED','NORMAL','role:WAREHOUSE',3,'Rules_src::grnShort',1,'NORMAL',5,'WAREHOUSE fewest open (buyer copied)','SRC_GRN_SHORT:<grn>','claim settled or \'no claim\' with reason','Sourcing › GRN › Supplier claim','claim amount (base)'),
(65,'SRC_LOT_NO_COST','Lot without final landed cost','Sourcing',NULL,'NORMAL','role:PARTNER',3,'Rules_src::lotNoCost',1,'NORMAL',5,'PARTNER','SRC_LOT_NO_COST:<lot>','cost > 0','Sourcing › Landed cost',NULL),
(66,'SRC_QTY_MISMATCH','Quantities do not balance','Sourcing','GRN_POSTED','NORMAL','role:STOCK',1,'Rules_src::qtyMismatch',1,'HIGH',5,'STOCK → WAREHOUSE fewest open','SRC_QTY_MISMATCH:<cycle product>','Balanced','Sourcing › Quantity check',NULL),
(67,'SRC_OVER_ALLOC','Allocation over the received quantity','Sourcing',NULL,'NORMAL','role:MANAGER',2,'Rules_src::overAlloc',1,'NORMAL',5,'PO owner → MANAGER fewest open','SRC_OVER_ALLOC:<cycle product>','planned = ordered','Sourcing › Destination allocation',NULL),
(68,'SRC_STEP_MISSING','Cycle step missing','Sourcing',NULL,'NORMAL','record.requested_by',3,'Rules_src::stepMissing',1,'NORMAL',5,'cycle owner (requested_by) → MANAGER fewest open','SRC_STEP_MISSING:<cycle>:<stage>','record added or step skipped','Sourcing › cycle step',NULL),
(69,'SRC_DOC_MISSING','Document missing','Sourcing',NULL,'NORMAL','record.requested_by',5,'Rules_src::docMissing',1,'NORMAL',5,'step owner / uploader → MANAGER fewest open','SRC_DOC_MISSING:<record>:<doc_type>','document linked / link fixed','Sourcing › record › Documents',NULL),
(70,'SRC_OLD_ENTRY_REJECTED','Old entry rejected – fix and resend','Sourcing',NULL,'NORMAL','record.created_by',2,'Rules_src::oldEntryRejected',1,'NORMAL',5,'requested_by → MANAGER fewest open','SRC_OLD_ENTRY_REJECTED:<old entry>','entry resubmitted and approved, or cancelled','Sourcing › Old entries',NULL),
(71,'STK_NO_LOCATION','Stock without a location','Stock',NULL,'NORMAL','role:STOCK',2,'Rules_stock::noLocation',1,'NORMAL',6,'STOCK fewest open','STK_NO_LOCATION:<product>','a location holds the stock','Stock › Locations',NULL),
(72,'STK_NO_COST','Stock without cost','Stock',NULL,'NORMAL','role:PARTNER',2,'Rules_stock::noCost',1,'NORMAL',6,'PARTNER','STK_NO_COST:<product>','cost > 0','Stock › Lots',NULL),
(73,'STK_LEDGER_MISMATCH','Ledger and product stock differ','Stock',NULL,'NORMAL','role:STOCK',1,'Rules_stock::ledgerDiff',1,'HIGH',6,'STOCK fewest open','STK_LEDGER_MISMATCH:<product>','equal again','Stock › Product ledger',NULL),
(74,'STK_LOW','Stock low','Stock',NULL,'NORMAL','role:MANAGER',2,'Rules_stock::low',1,'NORMAL',6,'STOCK fewest open','STK_LOW:<product>','available above min','Stock › Stock alerts',NULL),
(76,'STK_FC_DIFF','Fulfilment centre report differs from its location','Stock','IMPORT_APPLIED','NORMAL','role:STOCK',2,'Rules_stock::fcDiff',1,'HIGH',6,'WAREHOUSE fewest open','STK_FC_DIFF:<fba_shipment or location>:<sku>','difference 0 or adjusted with reason','Labels › Inbound shipment',NULL),
(77,'FIN_CASH_LOW','Free cash below the alert limit','Finance',NULL,'NORMAL','role:PARTNER',0,'Rules_fin::cashLow',1,'HIGH',10,'OWNER','FIN_CASH_LOW:<company>','free cash ≥ limit','Finance › Home',NULL),
(78,'FIN_PAYOUT_DIFF','FA2 matched payout ≠ bank deposit by > payout_tolerance','Finance',NULL,'NORMAL','role:FINANCE',2,'Rules_finance::payoutDiff',1,'HIGH',10,'FINANCE fewest open','FIN_PAYOUT_DIFF:<payout>','difference within tolerance','Finance › Reconciliation','difference (base)'),
(79,'FIN_SETTLEMENT_OVERDUE','FA3 no money file for ≥ settlement_overdue_days per shop','Finance',NULL,'NORMAL','role:FINANCE',2,'Rules_finance::settlementOverdue',1,'HIGH',10,'FINANCE fewest open → shop VA','FIN_SETTLEMENT_OVERDUE:<shop>','new money file imported','Import Center',NULL),
(80,'FIN_BANK_STALE','FA4 no bank line for ≥ bank_overdue_days per account','Finance',NULL,'NORMAL','role:FINANCE',2,'Rules_finance::bankStale',1,'NORMAL',10,'FINANCE fewest open','FIN_BANK_STALE:<bank account>','bank file imported','Import Center (bank)',NULL),
(81,'FIN_REFUND_RATE','FA5 SKU refund rate > 2 × 90-day average and ≥ 2 %','Finance',NULL,'NORMAL','role:TL',2,'Rules_finance::refundRate',1,'NORMAL',10,'shop TL → MANAGER','FIN_REFUND_RATE:<sku>','rate back under limit','Finance › Product periods',NULL),
(82,'FIN_LARGE_FEE','FA6 fee line ≤ −large_fee_gbp in last 30 days','Finance',NULL,'NORMAL','role:FINANCE',2,'Rules_finance::largeFee',1,'NORMAL',10,'FINANCE fewest open','FIN_LARGE_FEE:<money line>','reviewed (note) or out of window','Finance › Money lines',NULL),
(83,'FIN_SLOW_STOCK','FA7 SKU no sale ≥ slow_days (SLOW) or ≥ dead_days (DEAD)','Finance',NULL,'NORMAL','role:MANAGER',2,'Rules_finance::slowStock',1,'LOW',10,'MANAGER fewest open','FIN_SLOW_STOCK:<sku>:<SLOW|DEAD>','sale happens or stock 0','Stock › Slow / dead',NULL),
(84,'FIN_AGREEMENT_ENDING','FA8 ACTIVE agreement ends within agreement_ending_days','Finance',NULL,'NORMAL','role:OWNER',2,'Rules_finance::agreementEnding',1,'NORMAL',10,'OWNER','FIN_AGREEMENT_ENDING:<agreement>','renewed or ended','Finance › Agreements',NULL),
(85,'FIN_PERIOD_OPEN','FA10 shop × month still not LOCKED period_open_days after its end','Finance',NULL,'NORMAL','role:FINANCE',2,'Rules_finance::periodOpen',1,'HIGH',10,'FINANCE → OWNER','FIN_PERIOD_OPEN:<shop>:<YYYY-MM>','month LOCKED','Month Close › run',NULL),
(87,'FIN_PARTNER_QUERY','Partner asked a question','Finance',NULL,'NORMAL','role:OWNER',1,'Rules_fin::partnerQuery',1,'HIGH',10,'OWNER','FIN_PARTNER_QUERY:<query>','query answered','Finance › Statement queries',NULL),
(88,'FIN_FX_MISSING','Amount without exchange rate','Finance',NULL,'NORMAL','role:FINANCE',1,'Rules_fin::fxMissing',1,'HIGH',10,'FINANCE fewest open','FIN_FX_MISSING:<currency>:<YYYY-MM-DD>','rate added','Finance › FX rates',NULL),
(89,'FIN_REPORT_MISSING','Required money report of the profile not imported by month end + money_lag_days + overdue_days','Finance',NULL,'NORMAL','role:FINANCE',2,'Rules_finance::reportMissing',1,'HIGH',10,'FINANCE fewest open → shop VA','FIN_REPORT_MISSING:<shop>:<report type>:<YYYY-MM>','report imported','Import Center',NULL),
(90,'FIN_BANK_UNMATCHED','Bank line unmatched longer than BANK_UNMATCHED_DAYS (7)','Finance',NULL,'NORMAL','role:FINANCE',2,'Rules_finance::bankUnmatched',1,'NORMAL',10,'FINANCE fewest open','FIN_BANK_UNMATCHED:<bank line>','line matched or IGNORED','Finance › Reconciliation','line amount (base)'),
(91,'FIN_CLOSE_CHECK','MONTH_CHECKED: a close check of a shop × month run is RED','Finance','MONTH_CHECKED','NORMAL','role:FINANCE',2,'Rules_finance::closeCheck',1,'HIGH',10,'owner of the fix area → FINANCE fewest open','FIN_CLOSE_CHECK:<run>:<check>','check GREEN, WAIVED or NA','Month Close › run › check Fix link',NULL),
(92,'FIN_VA_STATEMENT','VA statement ready for approval, or approved VA share / salary unpaid after month LOCKED','Finance',NULL,'NORMAL','role:FINANCE',2,'Rules_finance::vaStatement',1,'NORMAL',10,'FINANCE fewest open → OWNER','FIN_VA_STATEMENT:<va>:<shop>:<YYYY-MM>','statement approved and payout matched','Finance › VA statements',NULL),
(93,'ACC_DUE_ITEM','Renewal / filing / payment due','Accounts',NULL,'NORMAL','due.owner',0,'Due_engine::run',1,'NORMAL',12,'due source owner rule (record person / role) → category default; category per due source (default PEOPLE)','ACC_DUE_ITEM:<due source>:<record>:<YYYY-MM-DD>','due source done rule','Accounts › person › tab of the item',NULL),
(94,'ACC_COMPLIANCE_DUE','Company filing / VAT / PAYE / corporation tax date within remind days','Compliance',NULL,'NORMAL','role:MANAGER',5,'Rules_compliance::complianceDue',1,'HIGH',13,'company compliance owner','ACC_COMPLIANCE_DUE:<company>:<obligation>:<YYYY-MM-DD>','filed (proof uploaded)','Accountancy Hub › company',NULL),
(95,'ACC_BANK_PHONE_CHECK','Supplier / forwarder / payee bank change pending SUP_BANK approval with no phone check recorded','Finance',NULL,'NORMAL','role:FINANCE',2,'Rules_finance::bankPhoneCheck',1,'HIGH',10,'FINANCE fewest open (not the maker)','ACC_BANK_PHONE_CHECK:<bank row>','phone check recorded or change rejected','Approvals › bank change',NULL),
(96,'WH_PUTAWAY_WAITING','Units waiting for putaway','Warehouse',NULL,'NORMAL','role:WAREHOUSE',1,'Rules_wh::putawayWaiting',1,'NORMAL',6,'record.received_by,role:WAREHOUSE','WH_PUTAWAY_WAITING:<site>:<sku>','RECEIVING qty 0','#/work/putaway',NULL),
(97,'WH_ORDER_SHORT','Order line short at intake','Warehouse','STOCK_RESERVED','NORMAL','role:STOCK',0,'Rules_wh::orderShort',1,'HIGH',6,'role:STOCK','WH_ORDER_SHORT:<sale line>','fully reserved or cancelled','#/wh/orders?filter=short',NULL),
(98,'WH_SHORT_PICK','Short pick – count the bin','Warehouse','SHORT_PICK','NORMAL','role:STOCK',0,'Rules_wh::shortPick',1,'HIGH',6,'role:STOCK','WH_SHORT_PICK:<bin>:<sku>','triggered count posted','#/work/count',NULL),
(99,'WH_PICK_ABANDONED','Pick list abandoned','Warehouse',NULL,'NORMAL','role:STOCK',0,'Rules_wh::pickAbandoned',1,'NORMAL',6,'record.supervisor,role:STOCK','WH_PICK_ABANDONED:<pick task>','task finished','#/wh/picklists',NULL),
(100,'WH_DISPATCH_DUE','Orders due for dispatch not ready','Warehouse',NULL,'NORMAL','role:WAREHOUSE',0,'Rules_wh::dispatchDue',1,'HIGH',7,'role:WAREHOUSE','WH_DISPATCH_DUE:<shop>:<date>','all Ready or handed over','#/wh/orders?filter=due',NULL),
(101,'WH_NOT_HANDED_OVER','Ready parcels not handed over','Warehouse',NULL,'NORMAL','role:WAREHOUSE',0,'Rules_wh::notHandedOver',1,'HIGH',7,'role:WAREHOUSE','WH_NOT_HANDED_OVER:<carrier>:<site>:<date>','all handed over or voided','#/work/handover',NULL),
(102,'WH_HANDOVER_EXCEPTION','Handover exceptions to resolve','Warehouse','HANDOVER_CLOSED','NORMAL','role:STOCK',1,'Rules_wh::handoverException',1,'NORMAL',7,'record.closed_by,role:STOCK','WH_HANDOVER_EXCEPTION:<session>','exceptions resolved','#/wh/handover',NULL),
(103,'WH_PARCEL_LOST','Lost parcel – follow up and claim','Warehouse','PARCEL_LOST','NORMAL','role:WAREHOUSE',2,'Rules_wh::parcelLost',1,'NORMAL',7,'shop.va,role:WAREHOUSE','WH_PARCEL_LOST:<parcel>','case closed','#/wh/parcels?filter=lost','claim_amount'),
(104,'WH_RETURN_UNMATCHED','Return without an order','Warehouse','RETURN_SCANNED','NORMAL','role:WAREHOUSE',2,'Rules_wh::returnUnmatched',1,'NORMAL',6,'record.received_by,role:WAREHOUSE','WH_RETURN_UNMATCHED:<return intake>','matched or found stock','#/work/returns?tab=match',NULL),
(105,'WH_RETURN_REFUND_REVIEW','Return received – review refund','Warehouse','RETURN_RECEIVED','NORMAL','shop.va',1,'Rules_wh::returnRefundReview',1,'NORMAL',2,'shop.va,shop.tl','WH_RETURN_REFUND_REVIEW:<return>','refund decided','#/sales/refund-proposals',NULL),
(106,'WH_COUNT_DUE','Count due','Warehouse',NULL,'NORMAL','role:STOCK',1,'Rules_wh::countDue',1,'NORMAL',6,'role:STOCK','WH_COUNT_DUE:<count>','count posted or cancelled','#/work/count',NULL),
(107,'WH_COUNT_RECOUNT','Recount needed','Warehouse','COUNT_SUBMITTED','NORMAL','role:STOCK',0,'Rules_wh::recount',1,'HIGH',6,'role:STOCK','WH_COUNT_RECOUNT:<count>:<bin>:<sku>','recount entered','#/work/count?tab=recount',NULL),
(108,'WH_MISSING_STOCK','Missing units to find or write off','Warehouse',NULL,'NORMAL','role:STOCK',3,'Rules_wh::missingStock',1,'NORMAL',6,'role:STOCK,role:MANAGER','WH_MISSING_STOCK:<site>:<sku>','MISSING qty 0','#/wh/missing',NULL),
(109,'WH_REPLEN','Replenish the pick home','Warehouse',NULL,'NORMAL','role:WAREHOUSE',1,'Rules_wh::replen',1,'NORMAL',6,'role:WAREHOUSE','WH_REPLEN:<site>:<sku>','home at or above min','#/work/pick?type=replen',NULL),
(110,'WH_RECON_MISMATCH','Warehouse and book stock differ','Warehouse','WH_RECON_DONE','NORMAL','role:STOCK',0,'Rules_wh::reconMismatch',1,'HIGH',6,'role:STOCK','WH_RECON_MISMATCH:<site>:<sku>:<check>','equal again','#/wh/health',NULL),
(111,'WH_UNASSIGNED_STOCK','Stock still unassigned','Warehouse',NULL,'NORMAL','role:STOCK',3,'Rules_wh::unassigned',1,'LOW',6,'role:STOCK','WH_UNASSIGNED_STOCK:<site>:<sku>','UNASSIGNED qty 0','#/work/putaway?from=unassigned',NULL),
(112,'WH_SKU_NO_HOME','SKU has no home bin','Warehouse',NULL,'NORMAL','role:STOCK',7,'Rules_wh::noHome',1,'LOW',6,'role:STOCK','WH_SKU_NO_HOME:<site>:<sku>','home set or stock 0','#/products?tab=homes',NULL),
(113,'WH_UNSHIPPED_LINES','Own-stock lines with no shipment','Warehouse',NULL,'NORMAL','role:WAREHOUSE',0,'Rules_wh::unshippedLines',1,'HIGH',2,'role:WAREHOUSE,shop.va','WH_UNSHIPPED_LINES:<batch>','all shipped or backfilled','#/sales/batch?view=confirm',NULL),
(114,'WH_SHIPPED_AWAITING','Shipped units waiting for Month Confirm','Warehouse',NULL,'NORMAL','shop.va',0,'Rules_wh::shippedAwaiting',1,'HIGH',2,'shop.va,shop.tl','WH_SHIPPED_AWAITING:<shop>:<YYYY-MM>','month batch confirmed','#/sales/imports',NULL),
(115,'WH_HOLD_AGED','Held stock waiting for a decision','Warehouse',NULL,'NORMAL','role:STOCK',3,'Rules_wh::holdAged',1,'NORMAL',6,'role:STOCK','WH_HOLD_AGED:<site>:<bin>:<sku>','units moved, written off or claimed','#/wh/held',NULL),
(116,'WH_PRINT_FAILED','Print job failed','Warehouse','PRINT_JOB_FAILED','NORMAL','role:ADMIN',0,'Rules_wh::printFailed',1,'NORMAL',7,'record.requested_by,role:ADMIN','WH_PRINT_FAILED:<printer>:<YYYY-MM-DD>','later job printed','#/labels/print-queue',NULL),
(117,'WH_ADJUST_UNUSUAL','Unusual number of adjustments','Warehouse',NULL,'NORMAL','role:MANAGER',2,'Rules_wh::adjustUnusual',1,'NORMAL',6,'role:MANAGER','WH_ADJUST_UNUSUAL:<user or sku>:<YYYY-WW>','reviewed','#/stock/adjustments',NULL),
(118,'WH_SLOTTING','Slotting suggestions waiting','Warehouse',NULL,'NORMAL','role:MANAGER',7,'Rules_wh::slotting',1,'LOW',6,'role:MANAGER','WH_SLOTTING:<site>:<YYYY-WW>','none open','#/wh/slotting',NULL),
(127,'PRD_CERT_EXPIRY','Product certificate expires soon','Products','DUE_SOON','NORMAL','role:MANAGER',7,'Due_engine::certificate',1,'NORMAL',13,'product.owner,role:MANAGER','PRD_CERT_EXPIRY:<certificate>:<YYYY-MM-DD>','expiry date moved forward or certificate inactive','#/product/certificates',NULL),
(128,'RES_STALE','Research not moved – decide or park','Sourcing',NULL,'NORMAL','role:MANAGER',3,'Rules_sourcing::researchStale',1,'LOW',5,'researcher → MANAGER fewest open','RES_STALE:<research>','status changed or PARKED','Buying › Research','');
DROP TABLE IF EXISTS `sys_saved_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_saved_views` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `page_key` varchar(60) NOT NULL,
  `view_name` varchar(80) NOT NULL,
  `filters_json` text NOT NULL,
  `columns_json` text DEFAULT NULL,
  `f_default` tinyint(4) NOT NULL DEFAULT 0,
  `f_shared` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_user` (`fk_id_account`,`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_search_index`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_search_index` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `entity` varchar(40) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  `department` varchar(40) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `search_text` text NOT NULL,
  `sound_key` varchar(64) DEFAULT NULL,
  `url` varchar(255) NOT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  `amount_value` decimal(14,2) DEFAULT NULL,
  `phone_norm` varchar(30) DEFAULT NULL,
  `email_norm` varchar(150) DEFAULT NULL,
  `actions_json` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_entity` (`entity`,`entity_id`),
  KEY `ix_ent` (`fk_id_ent`,`department`),
  KEY `ix_ssi_amount` (`fk_id_ent`,`amount_value`),
  KEY `ix_ssi_phone` (`phone_norm`),
  FULLTEXT KEY `ft_text` (`search_text`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_search_recent`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_search_recent` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `fk_id_ent` int(11) NOT NULL,
  `query_text` varchar(200) NOT NULL,
  `picked_entity` varchar(40) DEFAULT NULL,
  `picked_entity_id` bigint(20) DEFAULT NULL,
  `result_count` int(11) NOT NULL DEFAULT 0,
  `f_pinned` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_ssr_user` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Recent and pinned searches per user (R-G_CORE-100)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_search_synonyms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_search_synonyms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `term` varchar(80) NOT NULL,
  `maps_to` varchar(120) NOT NULL,
  `synonym_kind` varchar(16) NOT NULL DEFAULT 'ALIAS',
  `target_url` varchar(255) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_syn` (`fk_id_ent`,`term`,`maps_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Search aliases and synonyms (R-G_CORE-99)';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `sys_search_synonyms` VALUES
(1,0,'calculator','icc inventory cycle calculator','ALIAS','#/icc',1,NULL,'2026-11-12 04:31:48'),
(2,0,'rights','permissions','ALIAS','#/permissions',1,NULL,'2026-11-12 04:31:48'),
(3,0,'po','purchase order','ABBREVIATION',NULL,1,NULL,'2026-11-12 04:31:48'),
(4,0,'ds','dropshipping','ABBREVIATION',NULL,1,NULL,'2026-11-12 04:31:48'),
(5,0,'grn','goods received note','ABBREVIATION',NULL,1,NULL,'2026-11-12 04:31:48'),
(6,0,'lc','landed cost','ABBREVIATION',NULL,1,NULL,'2026-11-12 04:31:48'),
(7,0,'va','virtual assistant','ABBREVIATION',NULL,1,NULL,'2026-11-12 04:31:48'),
(8,0,'sku','product','ALIAS',NULL,1,NULL,'2026-11-12 04:31:48'),
(9,0,'supplier','vendor','ALIAS',NULL,1,NULL,'2026-11-12 04:31:48'),
(10,0,'vendor','supplier','ALIAS',NULL,1,NULL,'2026-11-12 04:31:48'),
(11,0,'hisab','accounts finance','ROMAN_URDU',NULL,1,NULL,'2026-11-12 04:31:48'),
(12,0,'maal','stock goods','ROMAN_URDU',NULL,1,NULL,'2026-11-12 04:31:48'),
(13,0,'paisa','money payment','ROMAN_URDU',NULL,1,NULL,'2026-11-12 04:31:48');
DROP TABLE IF EXISTS `sys_security_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_security_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_type` varchar(20) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `question` varchar(255) NOT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `legacy_table` varchar(64) DEFAULT NULL,
  `legacy_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_q_owner` (`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Security-question library';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_sequences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `doc_type` varchar(10) NOT NULL,
  `yymm` char(4) NOT NULL,
  `last_no` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seq` (`fk_id_ent`,`doc_type`,`yymm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_settings` (
  `fk_id_ent` int(11) NOT NULL,
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`fk_id_ent`,`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `sys_settings` VALUES
(0,'ACCOUNT_CODE_SUFFIX_EXTERNAL','EX',NULL,NULL),
(0,'ACCOUNT_CODE_TRIES','500',NULL,NULL),
(0,'ACC_CS_DUE_DAYS','14',NULL,NULL),
(0,'ACC_FRD_WINDOW_DAYS','45',NULL,NULL),
(0,'ADJUST_APPROVAL_QTY','50',NULL,NULL),
(0,'agreement_ending_days','30',NULL,NULL),
(0,'alert_repeat_days','2',NULL,NULL),
(0,'ALLOW_NEGATIVE_STOCK','0',NULL,NULL),
(0,'bank_overdue_days','8',NULL,NULL),
(0,'BANK_UNMATCHED_DAYS','7',NULL,NULL),
(0,'BASE_CURRENCY',NULL,NULL,NULL),
(0,'CAPCALL_MATCH_SIMILARITY','0.8',NULL,NULL),
(0,'CERT_REMIND_DAYS','60',NULL,NULL),
(0,'CLOSE_DEADLINE_DAY',NULL,NULL,NULL),
(0,'COMPANY_COUNTRY',NULL,NULL,NULL),
(0,'COMPANY_TIMEZONE',NULL,NULL,NULL),
(0,'cs_date_is','REVIEW',NULL,NULL),
(0,'CUSTOMER_DATA_RETENTION_MONTHS','24',NULL,NULL),
(0,'CUTOVER_MONTH',NULL,NULL,NULL),
(0,'DAILY_ORDERS_CLOSE_TIME',NULL,NULL,NULL),
(0,'DASH_MANAGER',NULL,NULL,NULL),
(0,'DASH_PARTNER',NULL,NULL,NULL),
(0,'dead_days','180',NULL,NULL),
(0,'DEFAULT_CARRIER_SERVICE',NULL,NULL,NULL),
(0,'DEFAULT_COMPLIANCE_OWNER',NULL,NULL,NULL),
(0,'DEFAULT_HANDLER_PARTY',NULL,NULL,NULL),
(0,'DEFAULT_PAYMENT_METHOD',NULL,NULL,NULL),
(0,'DEFAULT_PROFIT_ROUTE','LOT_PROFIT',NULL,NULL),
(0,'DEFAULT_SHOP',NULL,NULL,NULL),
(0,'DEFAULT_STACKING_RULE','SPLIT_UNITS',NULL,NULL),
(0,'DEFAULT_STOCK_OWNER',NULL,NULL,NULL),
(0,'DIGEST_TIME',NULL,NULL,NULL),
(0,'DQ_TASK_BELOW_PCT','60',NULL,NULL),
(0,'DRIVE_ROOT_FOLDER',NULL,NULL,NULL),
(0,'DS_HOLD_DAYS','14',NULL,NULL),
(0,'DS_MARGIN_MIN',NULL,NULL,NULL),
(0,'DS_MIN_MARGIN','10',NULL,NULL),
(0,'DS_MIN_MATCH','3',NULL,NULL),
(0,'FREE_CASH_ALERT',NULL,NULL,NULL),
(0,'free_cash_alert_limit','10000',NULL,NULL),
(0,'history_months',NULL,NULL,NULL),
(0,'horizon_months',NULL,NULL,NULL),
(0,'HS_CODE_MAX_DIGITS','10',NULL,NULL),
(0,'HS_CODE_MIN_DIGITS','6',NULL,NULL),
(0,'HUB_TOLERANCE','1.00',NULL,NULL),
(0,'ICC_DEMAND_DAYS','90',NULL,NULL),
(0,'ICC_SAFETY_DAYS',NULL,NULL,NULL),
(0,'IMPORT_UNDO_DAYS','30',NULL,NULL),
(0,'IMP_WAITING_HOURS','24',NULL,NULL),
(0,'INVESTOR_ROLE',NULL,NULL,NULL),
(0,'INV_AUTO_GENERATE','0',NULL,NULL),
(0,'INV_DUE_DAYS',NULL,NULL,NULL),
(0,'IP_ALLOWLIST_MODE','OFF',NULL,NULL),
(0,'LABEL_REPRINT_ALERT','2',NULL,NULL),
(0,'large_fee_gbp','250',NULL,NULL),
(0,'LC_DUTY_METHOD','Value',NULL,NULL),
(0,'LC_FINAL_AUTO','1',NULL,NULL),
(0,'LC_FREIGHT_METHOD','Quantity',NULL,NULL),
(0,'LC_LOSS_UNITS','ABSORB',NULL,NULL),
(0,'LC_OVERRIDE_APPROVAL_PCT','10',NULL,NULL),
(0,'LIST_PAGE_SIZE','100',NULL,NULL),
(0,'LOGIN_ID_MAX','15',NULL,NULL),
(0,'LOOKALIKE_MOBILE_DIGITS','9',NULL,NULL),
(0,'MAIN_WAREHOUSE',NULL,NULL,NULL),
(0,'match_intercompany_days','2',NULL,NULL),
(0,'MATCH_PAYOUT_DAYS',NULL,NULL,NULL),
(0,'match_supplier_days','5',NULL,NULL),
(0,'match_supplier_pct','2',NULL,NULL),
(0,'match_withdrawal_days','5',NULL,NULL),
(0,'NON_SELLABLE_LOCATION_KINDS','DAMAGED,RETURNS,PRODUCTION,TRANSIT,SUPPLIER,CUSTOMER,RECEIVING,DISPATCH,SHIPPED,QUARANTINE,MISSING,WRITTEN_OFF',NULL,NULL),
(0,'OPENING_STOCK_DATE',NULL,NULL,NULL),
(0,'PAYOUT_TOLERANCE',NULL,NULL,NULL),
(0,'PDF_ENGINE',NULL,NULL,NULL),
(0,'period_open_days','10',NULL,NULL),
(0,'pool_auto_cover','0',NULL,NULL),
(0,'PRICE_HISTORY_REQUIRED','1',NULL,NULL),
(0,'PRINT_FAILOVER','ASK',NULL,NULL),
(0,'PRINT_SCALE_LIMIT_PCT','3',NULL,NULL),
(0,'PRINT_TEST_REQUIRED','1',NULL,NULL),
(0,'QUIET_FROM',NULL,NULL,NULL),
(0,'QUIET_TO',NULL,NULL,NULL),
(0,'RECYCLE_BIN_DAYS','30',NULL,NULL),
(0,'reminders_enabled','1',NULL,NULL),
(0,'RESEARCH_MIN_MARGIN','25',NULL,NULL),
(0,'RESEARCH_STALE_DAYS','14',NULL,NULL),
(0,'RETURN_RESTOCK_MISSING','WRITTEN_OFF',NULL,NULL),
(0,'rule_snooze_days','7',NULL,NULL),
(0,'SAMPLE_SPLIT_DAYS','90',NULL,NULL),
(0,'SEARCH_RECENT_KEEP','20',NULL,NULL),
(0,'SEE_MONEY_ROLES','OWNER,PARTNER,FINANCE',NULL,NULL),
(0,'SESSION_IDLE_MINUTES','120',NULL,NULL),
(0,'settlement_overdue_days','17',NULL,NULL),
(0,'slow_days','90',NULL,NULL),
(0,'SRC_MONEY_TOL','0.009',NULL,NULL),
(0,'SRC_PAY_FX_TOLERANCE_PCT',NULL,NULL,NULL),
(0,'SRC_PAY_REMIND_DAYS','3',NULL,NULL),
(0,'SRC_QC_PASS_GREEN','95',NULL,NULL),
(0,'SRC_SHIP_ARRIVING_DAYS','3',NULL,NULL),
(0,'supplier_due_days','3',NULL,NULL),
(0,'TASK_ACK_HOURS_DEFAULT','24',NULL,NULL),
(0,'TASK_DUE_DAYS_DEFAULT','3',NULL,NULL),
(0,'TASK_REPEAT_JOB_MINUTES','10',NULL,NULL),
(0,'TASK_URGENT_MONEY','1000',NULL,NULL),
(0,'TASK_WEEKLY_LIMIT',NULL,NULL,NULL),
(0,'TT_SETTLEMENT_TOLERANCE','0.00',NULL,NULL),
(0,'TWO_FA_REASK_MINUTES','60',NULL,NULL),
(0,'VA_EXPENSES_BEFORE_SHARE','0',NULL,NULL),
(0,'VA_SHARE_REFRESH_UNTIL','SETTLEMENT',NULL,NULL),
(0,'VOLUMETRIC_DIVISOR','5000',NULL,NULL),
(0,'WAIVABLE_CLOSE_CHECKS','ORDERS_VS_TXN,HUB_TOTALS,PAYOUT_VS_BANK,PURCHASE_VS_SUPPLIER,EXPENSES',NULL,NULL),
(0,'WA_COMMANDS_ENABLED','1',NULL,NULL),
(0,'WA_FAIL_LIMIT','5',NULL,NULL),
(0,'WA_PROVIDER',NULL,NULL,NULL),
(0,'WH_ABC_A_PCT','80',NULL,NULL),
(0,'WH_ABC_B_PCT','15',NULL,NULL),
(0,'WH_ABC_DAYS','90',NULL,NULL),
(0,'WH_ADJUST_ALERT_PER_WEEK','3',NULL,NULL),
(0,'WH_ADJUST_NOTE_QTY','5',NULL,NULL),
(0,'WH_AUTOPRINT_LABEL','N',NULL,NULL),
(0,'WH_AUTOPRINT_MANIFEST','Y',NULL,NULL),
(0,'WH_AUTOPRINT_ON_RECEIVE','N',NULL,NULL),
(0,'WH_AUTOPRINT_PACKING_SLIP','N',NULL,NULL),
(0,'WH_AUTOPRINT_PICK_SHEET','N',NULL,NULL),
(0,'WH_AUTOPRINT_SHELF','Y',NULL,NULL),
(0,'WH_BARCODE_TYPE','CODE128',NULL,NULL),
(0,'WH_BATCH_MAX_ORDERS','20',NULL,NULL),
(0,'WH_BIN_BARCODE_PREFIX','L:',NULL,NULL),
(0,'WH_BIN_LABEL_QR','N',NULL,NULL),
(0,'WH_BIN_MULTI_SKU','Y',NULL,NULL),
(0,'WH_COUNT_APPROVAL_VALUE','100',NULL,NULL),
(0,'WH_COUNT_BLIND','Y',NULL,NULL),
(0,'WH_COUNT_DAILY_MAX_BINS','20',NULL,NULL),
(0,'WH_COUNT_DAYS_A','30',NULL,NULL),
(0,'WH_COUNT_DAYS_B','90',NULL,NULL),
(0,'WH_COUNT_DAYS_C','180',NULL,NULL),
(0,'WH_COUNT_ON_ZERO','Y',NULL,NULL),
(0,'WH_CYCLE_COUNT_MODE','TRIGGERS_ONLY',NULL,NULL),
(0,'WH_ENABLED','N',NULL,NULL),
(0,'WH_HANDOVER_REOPEN_MINUTES','60',NULL,NULL),
(0,'WH_HANDOVER_SCAN_REQUIRED','Y',NULL,NULL),
(0,'WH_HOLD_AGED_DAYS','14',NULL,NULL),
(0,'WH_HOME_MIN_DEFAULT',NULL,NULL,NULL),
(0,'WH_LOC_FORMAT','{ZONE}-{LEVEL}-{BIN}',NULL,NULL),
(0,'WH_LOC_LEVEL_STYLE','LETTER',NULL,NULL),
(0,'WH_LOC_PAD','0',NULL,NULL),
(0,'WH_LOC_SEPARATOR','-',NULL,NULL),
(0,'WH_LOSS_LOT_RULE','FIFO',NULL,NULL),
(0,'WH_MISSING_WRITE_OFF_DAYS','30',NULL,NULL),
(0,'WH_OFFLINE_COUNT','N',NULL,NULL),
(0,'WH_PACKING_STOCK','N',NULL,NULL),
(0,'WH_PACKSLIP_SHOW_ADDRESS','N',NULL,NULL),
(0,'WH_PACK_SCAN_REQUIRED','N',NULL,NULL),
(0,'WH_PICKLIST_AUTO_MINUTES','0',NULL,NULL),
(0,'WH_PICK_ABANDON_HOURS','8',NULL,NULL),
(0,'WH_PICK_IDLE_MINUTES','120',NULL,NULL),
(0,'WH_PICK_MODE','SINGLE',NULL,NULL),
(0,'WH_PICK_STRATEGY','HOME_FIRST',NULL,NULL),
(0,'WH_PRESET','SIMPLE',NULL,NULL),
(0,'WH_PUTAWAY_STEP','N',NULL,NULL),
(0,'WH_RECEIVING_MAX_HOURS','24',NULL,NULL),
(0,'WH_RECEIVING_SELLABLE','N',NULL,NULL),
(0,'WH_RECOUNT_THRESHOLD_PCT','5',NULL,NULL),
(0,'WH_RECOUNT_THRESHOLD_UNITS','2',NULL,NULL),
(0,'WH_REPLEN_MODE','OFF',NULL,NULL),
(0,'WH_RESERVE_EXPIRY_HOURS','0',NULL,NULL),
(0,'WH_RESERVE_ON','INTAKE',NULL,NULL),
(0,'WH_RETURN_NEW_TO','HOME',NULL,NULL),
(0,'WH_RETURN_OPENED_TO','RETURNS',NULL,NULL),
(0,'WH_RETURN_UNMATCHED_DAYS','30',NULL,NULL),
(0,'WH_ROUTE_SNAKE','N',NULL,NULL),
(0,'WH_SCAN_CONFIRM_PICK','Y',NULL,NULL),
(0,'WH_SCAN_CONFIRM_PUTAWAY','Y',NULL,NULL),
(0,'WH_SCAN_CONFIRM_RECEIVE','Y',NULL,NULL),
(0,'WH_SCAN_CONFIRM_RETURN','Y',NULL,NULL),
(0,'WH_SCAN_COUNTS_UNITS','N',NULL,NULL),
(0,'WH_SCAN_MODE','BOTH',NULL,NULL),
(0,'WH_SEGREGATED_OWNERS',NULL,NULL,NULL),
(0,'WH_STORAGE_MODE','FIXED',NULL,NULL),
(0,'WH_SYSTEM_BIN_PREFIX','SYS-',NULL,NULL),
(0,'WH_UNASSIGNED_DAYS','14',NULL,NULL),
(0,'WH_UNASSIGNED_SELLABLE','Y',NULL,NULL),
(0,'WH_UNSHIPPED_AT_CONFIRM','ASK',NULL,NULL),
(0,'WH_WEEKEND_DAYS','6,7',NULL,NULL),
(0,'WH_ZONES_ON','N',NULL,NULL);
DROP TABLE IF EXISTS `sys_share_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_share_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `entity_type` varchar(20) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  `link_hash` char(64) NOT NULL,
  `purpose` varchar(20) NOT NULL,
  `fk_id_party` int(11) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_share` (`link_hash`),
  KEY `ix_share_ent` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='No-login supplier links (R-B_SOURCING-64)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_user_defaults`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_user_defaults` (
  `fk_id_account` int(11) NOT NULL,
  `form_key` varchar(60) NOT NULL,
  `field_key` varchar(60) NOT NULL,
  `default_value` varchar(255) DEFAULT NULL,
  `last_used_value` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`fk_id_account`,`form_key`,`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_user_nav`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_user_nav` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `kind` enum('FAVOURITE','RECENT') NOT NULL,
  `title` varchar(150) NOT NULL,
  `url` varchar(255) NOT NULL,
  `used_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nav` (`fk_id_account`,`kind`,`url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_user_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_user_sessions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `session_hash` char(64) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `device_label` varchar(120) DEFAULT NULL,
  `fk_id_mobile_device` int(11) DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `end_reason` varchar(12) DEFAULT NULL,
  `ended_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sess` (`session_hash`),
  KEY `ix_sess_user` (`user_id`,`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Who is logged in from where; force logout (R-C-49)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `sys_workflow_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_workflow_state` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workflow` varchar(40) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  `step_key` varchar(40) NOT NULL,
  `status` enum('LOCKED','OPEN','DONE','SKIPPED','OVERRIDDEN') NOT NULL DEFAULT 'LOCKED',
  `reason` varchar(255) DEFAULT NULL,
  `done_by` int(11) DEFAULT NULL,
  `done_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_step` (`workflow`,`entity_id`,`step_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `account_code` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `first_name` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `middle_name` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `sur_name` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `title` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `d_birth` date DEFAULT NULL,
  `remarks` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `login_id` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `pass` varchar(255) DEFAULT NULL,
  `f_active` int(11) NOT NULL,
  `f_login` tinyint(4) NOT NULL DEFAULT 0,
  `current_nationality` int(11) NOT NULL,
  `old_nationality` int(11) NOT NULL,
  `current_passport_no` varchar(10) NOT NULL,
  `current_passport_from` varchar(50) NOT NULL,
  `current_passport_expires` varchar(50) NOT NULL,
  `dual_passport_no` varchar(100) NOT NULL,
  `dual_passport_from` varchar(50) NOT NULL,
  `dual_passport_expires` varchar(50) NOT NULL,
  `current_cnic_no` varchar(100) NOT NULL,
  `current_cnic_from` varchar(50) NOT NULL,
  `current_cnic_expires` varchar(50) NOT NULL,
  `dual_cnic_no` varchar(100) NOT NULL,
  `dual_cnic_from` varchar(50) NOT NULL,
  `dual_cnic_expires` varchar(50) NOT NULL,
  `resident_permit_no` varchar(100) NOT NULL,
  `resident_permit_from` varchar(50) NOT NULL,
  `resident_permit_expires` varchar(50) NOT NULL,
  `driving_license_no` varchar(100) NOT NULL,
  `driving_license_from` varchar(50) NOT NULL,
  `driving_license_expires` varchar(50) NOT NULL,
  `account_pc` int(11) NOT NULL DEFAULT 0,
  `private_notes` text NOT NULL,
  `d_of_birth` date NOT NULL DEFAULT '0000-00-00',
  `emergency_contact` varchar(100) NOT NULL,
  `emergency_number` varchar(80) NOT NULL,
  `emergency_relation` varchar(100) NOT NULL,
  `whatsapp_number` varchar(30) DEFAULT NULL,
  `timezone` varchar(40) DEFAULT NULL,
  `quiet_from` time DEFAULT NULL,
  `quiet_to` time DEFAULT NULL,
  `digest_time` time NOT NULL DEFAULT '08:00:00',
  `wa_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `email_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `default_helper_id` int(11) DEFAULT NULL,
  `weekly_task_limit` int(11) NOT NULL DEFAULT 6,
  `l_account_kind` char(1) NOT NULL DEFAULT 'M' COMMENT 'M=Managed Account, E=External Contact',
  `f_pass_hashed` tinyint(4) NOT NULL DEFAULT 0,
  `f_must_change_pass` tinyint(4) NOT NULL DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `fk_id_merged_into` int(11) DEFAULT NULL,
  `l_party_type` char(1) NOT NULL DEFAULT 'P',
  `record_status` varchar(8) NOT NULL DEFAULT 'ACTIVE',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ent_loginid` (`fk_id_ent`,`login_id`),
  UNIQUE KEY `uq_whatsapp` (`whatsapp_number`),
  KEY `fk_id_ent_account` (`fk_id_ent`),
  KEY `idx_account_no_in_t_account` (`account_code`),
  KEY `idx_account_kind` (`fk_id_ent`,`l_account_kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_2fa`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_2fa` (
  `fk_id_account` int(11) NOT NULL,
  `method` enum('TOTP','WHATSAPP','EMAIL') NOT NULL DEFAULT 'TOTP',
  `secret_enc` varbinary(255) DEFAULT NULL,
  `f_enabled` tinyint(4) NOT NULL DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`fk_id_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_addresses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `f_international` tinyint(4) NOT NULL,
  `line1` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `line2` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `line3` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `line4` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `line5` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `post_code` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `counti` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_country` int(11) NOT NULL,
  `l_address_type` enum('H','O') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_used_for_all_entities` tinyint(4) NOT NULL,
  `f_active` int(11) NOT NULL,
  `reference_address` varchar(255) NOT NULL,
  `old_address1` varchar(255) NOT NULL,
  `old_address2` varchar(255) NOT NULL,
  `old_address3` varchar(255) NOT NULL,
  `d_lived_from_addr1` date NOT NULL DEFAULT '0000-00-00',
  `d_lived_upto_addr1` date NOT NULL DEFAULT '0000-00-00',
  `d_lived_from_addr2` date NOT NULL DEFAULT '0000-00-00',
  `d_lived_upto_addr2` date NOT NULL DEFAULT '0000-00-00',
  PRIMARY KEY (`id`),
  KEY `fk_id_account_address` (`fk_id_account`),
  KEY `fk_id_country_acc_address` (`fk_id_country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_addresses_entities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_addresses_entities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_entites` int(11) NOT NULL,
  `fk_id_address` int(11) NOT NULL,
  `f_active` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_id_address_acc_addr_in_entity` (`fk_id_address`),
  KEY `fk_id_entity_acc_addr_in_entity` (`fk_id_entites`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_bank_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_bank_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_t_account_bank_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_banks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_banks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `country_id` int(11) NOT NULL,
  `fk_bank_id` int(11) NOT NULL,
  `linked_mobile` int(11) NOT NULL,
  `linked_email` int(11) NOT NULL,
  `linked_address` int(11) NOT NULL,
  `account_no` varchar(150) DEFAULT NULL,
  `sort_branch_code` varchar(80) DEFAULT NULL,
  `iban_no` varchar(150) DEFAULT NULL,
  `swift_code` varchar(150) DEFAULT NULL,
  `card_type` varchar(50) DEFAULT NULL,
  `fk_card_category_id` int(11) DEFAULT NULL,
  `card_number` varchar(150) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_upto` date DEFAULT NULL,
  `card_cvv` varchar(20) DEFAULT NULL,
  `front_four_digits` varchar(20) DEFAULT NULL,
  `name_on_card` varchar(200) DEFAULT NULL,
  `app_mobile_no_id` int(11) DEFAULT NULL,
  `app_pin` varchar(50) DEFAULT NULL,
  `atm_pin` varchar(50) DEFAULT NULL,
  `web_login_id` varchar(150) DEFAULT NULL,
  `membership_no` varchar(100) DEFAULT NULL,
  `web_login_pass` varchar(150) DEFAULT NULL,
  `mother_name` varchar(150) DEFAULT NULL,
  `memorable_word` varchar(150) DEFAULT NULL,
  `memorable_date` varchar(80) DEFAULT NULL,
  `favorite_food` varchar(100) DEFAULT NULL,
  `favorite_place` varchar(100) DEFAULT NULL,
  `favorite_author` varchar(100) DEFAULT NULL,
  `fk_question_id1` int(11) DEFAULT 0,
  `answer1` varchar(150) DEFAULT NULL,
  `fk_question_id2` int(11) DEFAULT 0,
  `answer2` varchar(150) DEFAULT NULL,
  `fk_question_id3` int(11) DEFAULT 0,
  `answer3` varchar(150) DEFAULT NULL,
  `fk_question_id4` int(11) DEFAULT 0,
  `answer4` varchar(150) DEFAULT NULL,
  `fk_question_id5` int(11) DEFAULT 0,
  `answer5` varchar(150) DEFAULT NULL,
  `fk_question_id6` int(11) DEFAULT 0,
  `answer6` varchar(150) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL,
  `account_type` varchar(255) NOT NULL DEFAULT 'Personal',
  `fk_id_company` int(11) NOT NULL DEFAULT 0,
  `fk_currency_id` int(11) NOT NULL DEFAULT 0 COMMENT 'cat_currency.id, 0 = not set',
  `card_last4` char(4) DEFAULT NULL,
  `vault_ref` varchar(255) DEFAULT NULL,
  `fk_id_src_supplier` int(11) DEFAULT NULL,
  `beneficiary_name` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_branch` int(11) NOT NULL,
  `fk_id_account` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `f_active` tinyint(1) NOT NULL DEFAULT 1,
  `role_code` varchar(20) DEFAULT NULL,
  `d_from` date DEFAULT NULL,
  `d_to` date DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_company_houses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_company_houses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_company_house_id` int(11) DEFAULT NULL,
  `fk_country_id` int(11) DEFAULT NULL,
  `company_photo_submitted_id` int(11) DEFAULT NULL,
  `photo_submitted_date` date DEFAULT NULL,
  `creation_date` date DEFAULT NULL,
  `confirmation_statement_date` date DEFAULT NULL,
  `file_return_date` date DEFAULT NULL,
  `company_login_pc_id` int(11) DEFAULT NULL,
  `company_login_pc_password` varchar(100) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL,
  `fk_doc_submitted_id` int(11) NOT NULL,
  `doc_submitted_date` date NOT NULL DEFAULT '2023-01-24',
  `fk_question_id1` int(11) NOT NULL DEFAULT 0,
  `answer1` varchar(150) NOT NULL,
  `fk_question_id2` int(11) NOT NULL DEFAULT 0,
  `answer2` varchar(150) NOT NULL,
  `fk_question_id3` int(11) NOT NULL DEFAULT 0,
  `answer3` varchar(150) NOT NULL,
  `fk_question_id4` int(11) NOT NULL DEFAULT 0,
  `answer4` varchar(150) NOT NULL,
  `fk_question_id5` int(11) NOT NULL DEFAULT 0,
  `answer5` varchar(150) NOT NULL,
  `fk_question_id6` int(11) NOT NULL DEFAULT 0,
  `answer6` varchar(15) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_credit_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_credit_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `card_account_type` enum('Personal','Business') DEFAULT 'Personal',
  `fk_cc_provider_id` int(11) DEFAULT NULL,
  `country_id` int(11) NOT NULL,
  `fk_bank_id` int(11) NOT NULL,
  `linked_mobile` int(11) NOT NULL,
  `linked_email` int(11) NOT NULL,
  `linked_personal_address` int(11) NOT NULL,
  `linked_business_address` int(11) DEFAULT NULL,
  `account_no` varchar(150) DEFAULT NULL,
  `sort_branch_code` varchar(80) DEFAULT NULL,
  `iban_no` varchar(150) DEFAULT NULL,
  `swift_code` varchar(150) DEFAULT NULL,
  `card_type` varchar(50) DEFAULT NULL,
  `fk_card_category_id` int(11) DEFAULT NULL,
  `card_number` varchar(150) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_upto` date DEFAULT NULL,
  `card_cvv` varchar(20) DEFAULT NULL,
  `front_four_digits` varchar(20) DEFAULT NULL,
  `name_on_card` varchar(200) DEFAULT NULL,
  `app_mobile_no_id` int(11) DEFAULT NULL,
  `app_pin` varchar(50) DEFAULT NULL,
  `atm_pin` varchar(50) DEFAULT NULL,
  `web_login_id` varchar(150) DEFAULT NULL,
  `membership_no` varchar(100) DEFAULT NULL,
  `web_login_pass` varchar(150) DEFAULT NULL,
  `mother_name` varchar(150) DEFAULT NULL,
  `memorable_word` varchar(150) DEFAULT NULL,
  `memorable_date` varchar(80) DEFAULT NULL,
  `favorite_food` varchar(100) DEFAULT NULL,
  `favorite_place` varchar(100) DEFAULT NULL,
  `favorite_author` varchar(100) DEFAULT NULL,
  `fk_question_id1` int(11) DEFAULT 0,
  `answer1` varchar(150) DEFAULT NULL,
  `fk_question_id2` int(11) DEFAULT 0,
  `answer2` varchar(150) DEFAULT NULL,
  `fk_question_id3` int(11) DEFAULT 0,
  `answer3` varchar(150) DEFAULT NULL,
  `fk_question_id4` int(11) DEFAULT 0,
  `answer4` varchar(150) DEFAULT NULL,
  `fk_question_id5` int(11) DEFAULT 0,
  `answer5` varchar(150) DEFAULT NULL,
  `fk_question_id6` int(11) DEFAULT 0,
  `answer6` varchar(150) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL,
  `fk_id_company` int(11) NOT NULL,
  `credit_limit` int(11) NOT NULL,
  `f_card_billing_payment_date` date NOT NULL,
  `card_last4` char(4) DEFAULT NULL,
  `vault_ref` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_data_scope`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_data_scope` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scope` (`fk_id_account`,`fk_id_ent`,`fk_id_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_doc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_doc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_id_doc_type` int(11) NOT NULL,
  `doc_no` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `f_validate` tinyint(4) NOT NULL,
  `d_expire` date DEFAULT NULL,
  `file_name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL,
  `d_valid_from` date NOT NULL DEFAULT '0000-00-00',
  PRIMARY KEY (`id`),
  KEY `fk_id_account_doc` (`fk_id_account`),
  KEY `fk_id_doc_type_in_acc_doc` (`fk_id_doc_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_emails` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_id_mobile` int(11) DEFAULT NULL,
  `email` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `recovery_email` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `recovery_owner_name` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `f_popup_notification` tinyint(4) NOT NULL,
  `f_active` tinyint(4) NOT NULL,
  `f_security` tinyint(4) NOT NULL,
  `popup_notification_mobile` int(11) DEFAULT NULL,
  `notification_email_pass` varchar(100) DEFAULT NULL,
  `question1` varchar(200) NOT NULL,
  `ans1` varchar(200) NOT NULL,
  `question2` varchar(200) NOT NULL,
  `ans2` varchar(200) NOT NULL,
  `question3` varchar(200) NOT NULL,
  `ans3` varchar(200) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_id_account_emails` (`fk_id_account`),
  KEY `fk_id_mobile_in_emails` (`fk_id_mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_employment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_employment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `fk_id_account` int(11) NOT NULL,
  `job_title` varchar(120) DEFAULT NULL,
  `fk_id_department` int(11) DEFAULT NULL,
  `fk_id_reports_to` int(11) DEFAULT NULL,
  `fk_id_contract_type` int(11) DEFAULT NULL,
  `d_probation_end` date DEFAULT NULL,
  `hours_per_week` decimal(5,2) DEFAULT NULL,
  `work_days` varchar(20) DEFAULT NULL,
  `shift_from` varchar(5) DEFAULT NULL,
  `shift_to` varchar(5) DEFAULT NULL,
  `leave_days_year` decimal(5,1) DEFAULT NULL,
  `fk_id_pay_cycle` int(11) DEFAULT NULL,
  `pay_day` tinyint(4) DEFAULT NULL,
  `monthly_target` varchar(255) DEFAULT NULL,
  `skills` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_tae_account` (`fk_id_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Employment details of a person (R-C-52) – Owner / HR only';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_import_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_import_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `import_name` varchar(200) NOT NULL,
  `system_columns` text NOT NULL,
  `csv_columns` text NOT NULL,
  `import_controller_name` varchar(100) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_in_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_in_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_id_account_type` int(11) NOT NULL,
  `f_active` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_uniq_1` (`fk_id_account`,`fk_id_account_type`),
  KEY `fk_id_acc_type_in_acc_types` (`fk_id_account_type`),
  KEY `fk_id_account_in_acc_types` (`fk_id_account`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_ipburgers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_ipburgers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `f_active` tinyint(4) DEFAULT NULL,
  `fk_id_account` int(11) NOT NULL,
  `ipb_acc_user_id` varchar(150) NOT NULL,
  `ipb_password` varchar(150) DEFAULT NULL,
  `ipb_marketplace_id` int(11) DEFAULT NULL,
  `fk_ipburger_id` int(11) NOT NULL,
  `ipb_ip_password` varchar(80) DEFAULT NULL,
  `renewal_date` varchar(150) DEFAULT NULL,
  `ipb_expires` date DEFAULT NULL,
  `fk_bank_id` int(11) DEFAULT NULL,
  `renewal_due` date DEFAULT NULL,
  `billing_cycle` enum('WEEKLY','MONTHLY','QUARTERLY','YEARLY','ONE_OFF') DEFAULT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  `vault_ref` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_job_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_job_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL,
  `d_job_start_date` date NOT NULL DEFAULT '0000-00-00',
  `d_contract_renewal_date` date NOT NULL DEFAULT '0000-00-00',
  `share_percentage` decimal(5,2) DEFAULT NULL,
  `pound_exchange_rate` decimal(12,6) DEFAULT NULL,
  `salary` int(11) NOT NULL,
  `salary_date` date NOT NULL DEFAULT '0000-00-00',
  `fk_id_branch` int(11) DEFAULT NULL,
  `d_end_date` date DEFAULT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_kind_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_kind_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `from_kind` char(1) NOT NULL,
  `to_kind` char(1) NOT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  `changed_by` int(11) NOT NULL DEFAULT 0,
  `changed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kind_log_account` (`fk_id_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_marketplaces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_marketplaces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `marketplace_type` enum('Personal','Business') NOT NULL DEFAULT 'Personal',
  `country_id` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL,
  `fk_id_bank` int(11) NOT NULL,
  `fk_id_bank_account` int(11) NOT NULL,
  `payout_mode` varchar(50) NOT NULL,
  `marketplace_starte_date` date DEFAULT NULL,
  `fk_photo_submitted_id` int(11) DEFAULT NULL,
  `photo_submitted_date` date DEFAULT NULL,
  `linked_mobile` int(11) DEFAULT NULL,
  `notificate_email` varchar(150) DEFAULT NULL,
  `cust_service_not_email` varchar(150) DEFAULT NULL,
  `general_email` varchar(150) DEFAULT NULL,
  `linked_email` int(11) DEFAULT NULL,
  `linked_address` int(11) DEFAULT NULL,
  `dispatch_address1` varchar(150) DEFAULT NULL,
  `dispatch_address2` varchar(150) DEFAULT NULL,
  `dispatch_address3` varchar(150) DEFAULT NULL,
  `return_address` varchar(150) DEFAULT NULL,
  `web_user_login_id` varchar(100) NOT NULL,
  `membership_no` varchar(100) NOT NULL,
  `web_login_password` varchar(100) NOT NULL,
  `app_linked_mobile` int(11) DEFAULT NULL,
  `app_user_id` varchar(100) NOT NULL,
  `app_login_pin` varchar(50) NOT NULL,
  `two_step_verification` tinyint(1) NOT NULL DEFAULT 1,
  `google_authenticator` tinyint(1) NOT NULL DEFAULT 1,
  `authenticator_device` varchar(100) NOT NULL,
  `authenticator_person` varchar(100) NOT NULL,
  `authenticator_fk_id_mobile` int(11) NOT NULL,
  `memorable_word` varchar(100) NOT NULL,
  `memorable_date` varchar(50) NOT NULL,
  `favorite_food` varchar(100) DEFAULT NULL,
  `favorite_place` varchar(100) DEFAULT NULL,
  `favorite_school` varchar(100) DEFAULT NULL,
  `favorite_friend` varchar(100) DEFAULT NULL,
  `favorite_actor` varchar(100) DEFAULT NULL,
  `favorite_author` varchar(100) DEFAULT NULL,
  `fk_question_id1` int(11) NOT NULL,
  `answer1` varchar(100) NOT NULL,
  `fk_question_id2` int(11) NOT NULL,
  `answer2` varchar(100) NOT NULL,
  `fk_question_id3` int(11) NOT NULL,
  `answer3` varchar(100) NOT NULL,
  `fk_question_id4` int(11) DEFAULT NULL,
  `answer4` varbinary(100) DEFAULT NULL,
  `fk_question_id5` int(11) DEFAULT NULL,
  `answer5` varchar(100) DEFAULT NULL,
  `fk_question_id6` int(11) DEFAULT NULL,
  `answer6` varchar(100) DEFAULT NULL,
  `f_active` tinyint(4) DEFAULT NULL,
  `fk_doc_submitted_id` int(11) NOT NULL,
  `doc_submitted_date` date NOT NULL DEFAULT '2013-01-09',
  `fk_id_bank_payment` int(11) NOT NULL,
  `fk_id_bank_payment_account` int(11) NOT NULL,
  `market_place_type` enum('Seller','Buyer') NOT NULL DEFAULT 'Seller',
  `delivery_address1` int(11) NOT NULL,
  `delivery_address2` int(11) NOT NULL,
  `delivery_address3` int(11) NOT NULL,
  `fk_id_company` int(11) NOT NULL,
  `fk_id_owner` int(11) NOT NULL,
  `store_profile_url` varchar(255) NOT NULL,
  `fk_id_shop` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_mobiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_mobiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `l_office_home` enum('O','H') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `fk_id_country` int(11) NOT NULL,
  `mobile_no` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `fk_id_mobile_network` int(11) DEFAULT NULL,
  `l_recharge_contract` enum('R','C') CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `simno` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_mobile_device` int(11) DEFAULT NULL,
  `slotno` int(11) DEFAULT NULL,
  `fk_id_address` int(11) DEFAULT NULL,
  `f_active` int(11) NOT NULL,
  `sms_forward_to_mobile` int(11) NOT NULL,
  `sms_forward_to_email` int(11) NOT NULL,
  `mobile_paired` int(11) NOT NULL,
  `next_recharge_date` varchar(50) NOT NULL,
  `mobile_capacity` varchar(50) NOT NULL,
  `other_fk_id_mobile` int(11) NOT NULL DEFAULT 0,
  `other_fk_id_email` int(11) NOT NULL DEFAULT 0,
  `fk_id_other_mobile_paired` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_id_account_mobiles` (`fk_id_account`),
  KEY `fk_id_address_in_acc_mobiles` (`fk_id_address`),
  KEY `fk_id_country_in_acc_mobiles` (`fk_id_country`),
  KEY `fk_id_network_mob_in_acc_mobiles` (`fk_id_mobile_network`),
  KEY `fk_id_mob_device_in_acc_mobiles` (`fk_id_mobile_device`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_paypal`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_paypal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `paypal_account_type` varchar(50) DEFAULT NULL,
  `paypal_name` varchar(150) DEFAULT NULL,
  `country_id` int(11) NOT NULL,
  `fk_bank_id` int(11) NOT NULL,
  `linked_mobile` int(11) DEFAULT NULL,
  `linked_email` int(11) DEFAULT NULL,
  `linked_address` int(11) DEFAULT NULL,
  `fk_photo_submitted_id` int(11) DEFAULT NULL,
  `linked_debit_card_id1` int(11) DEFAULT NULL,
  `linked_debit_card_id2` int(11) DEFAULT NULL,
  `linked_debit_card_id3` int(11) DEFAULT NULL,
  `sort_branch_code` varchar(80) DEFAULT NULL,
  `name_on_card` varchar(200) DEFAULT NULL,
  `app_mobile_no_id` int(11) DEFAULT NULL,
  `app_pin` varchar(50) DEFAULT NULL,
  `atm_pin` varchar(50) DEFAULT NULL,
  `web_login_id` varchar(150) DEFAULT NULL,
  `membership_no` varchar(100) DEFAULT NULL,
  `web_login_pass` varchar(150) DEFAULT NULL,
  `two_step_verification` tinyint(4) DEFAULT NULL,
  `google_authenticator` tinyint(4) DEFAULT NULL,
  `authenticator_device` int(11) DEFAULT NULL,
  `authenticator_person` varchar(150) DEFAULT NULL,
  `authenticator_mobile_set` int(11) DEFAULT NULL,
  `memorable_word` varchar(150) DEFAULT NULL,
  `memorable_date` varchar(80) DEFAULT NULL,
  `favorite_food` varchar(100) DEFAULT NULL,
  `favorite_place` varchar(100) DEFAULT NULL,
  `favorite_author` varchar(100) DEFAULT NULL,
  `fk_question_id1` int(11) DEFAULT 0,
  `answer1` varchar(150) DEFAULT NULL,
  `fk_question_id2` int(11) DEFAULT 0,
  `answer2` varchar(150) DEFAULT NULL,
  `fk_question_id3` int(11) DEFAULT 0,
  `answer3` varchar(150) DEFAULT NULL,
  `fk_question_id4` int(11) DEFAULT 0,
  `answer4` varchar(150) DEFAULT NULL,
  `fk_question_id5` int(11) DEFAULT 0,
  `answer5` varchar(150) DEFAULT NULL,
  `fk_question_id6` int(11) DEFAULT 0,
  `answer6` varchar(150) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL,
  `fk_doc_submitted_id` int(11) NOT NULL DEFAULT 0,
  `registered_mobile_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_pc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_pc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `pc_id` int(11) NOT NULL,
  `fk_marketplace_id` int(11) NOT NULL,
  `hotspot_mobile_id` int(11) NOT NULL,
  `fk_id_company` int(11) NOT NULL DEFAULT 0,
  `fk_id_owner` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_permission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_permission` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_item_id` int(11) NOT NULL,
  `username` varchar(200) DEFAULT NULL,
  `password` varchar(200) DEFAULT NULL,
  `billing_date` date DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `payment_mode` varchar(100) DEFAULT NULL,
  `fk_ipburger_id` int(11) DEFAULT 0,
  `fk_company_house_id` int(11) DEFAULT 0,
  `fk_question_id1` int(11) DEFAULT 0,
  `answer1` varchar(150) DEFAULT NULL,
  `fk_question_id2` int(11) DEFAULT 0,
  `answer2` varchar(150) DEFAULT NULL,
  `fk_question_id3` int(11) DEFAULT 0,
  `answer3` varchar(150) DEFAULT NULL,
  `fk_question_id4` int(11) DEFAULT 0,
  `answer4` varchar(150) DEFAULT NULL,
  `fk_question_id5` int(11) DEFAULT 0,
  `answer5` varchar(150) DEFAULT NULL,
  `fk_question_id6` int(11) DEFAULT 0,
  `answer6` varchar(150) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_photo_ids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_photo_ids` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `country_id` int(11) NOT NULL,
  `fk_photo_id` int(11) NOT NULL,
  `long_digit_number` varchar(200) NOT NULL,
  `photo_from_date` varchar(80) NOT NULL,
  `photo_expiry_date` varchar(80) NOT NULL,
  `f_active` tinyint(4) NOT NULL,
  `doc_url` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_prev_employment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_prev_employment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `fk_id_account` int(11) NOT NULL,
  `prev_company` varchar(190) NOT NULL,
  `prev_role` varchar(120) DEFAULT NULL,
  `d_from` date DEFAULT NULL,
  `d_to` date DEFAULT NULL,
  `leave_reason` varchar(255) DEFAULT NULL,
  `reference_name` varchar(120) DEFAULT NULL,
  `reference_phone` varchar(40) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_tape_account` (`fk_id_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Previous employment of a person (R-C-52) – Owner / HR only';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_rights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_rights` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_id_right` int(11) NOT NULL,
  `e_status` enum('GRANTED','REVOKED','NA') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_id_account_in_acc_rights` (`fk_id_account`),
  KEY `idx_id_right_in_acc_rights` (`fk_id_right`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_social_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_social_media` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_social_media_id` int(11) NOT NULL,
  `username` varchar(200) DEFAULT NULL,
  `password` varchar(200) DEFAULT NULL,
  `linked_mobile` int(11) NOT NULL,
  `linked_email` int(11) NOT NULL,
  `linked_address` int(11) NOT NULL,
  `fk_question_id1` int(11) DEFAULT 0,
  `answer1` varchar(150) DEFAULT NULL,
  `fk_question_id2` int(11) DEFAULT 0,
  `answer2` varchar(150) DEFAULT NULL,
  `fk_question_id3` int(11) DEFAULT 0,
  `answer3` varchar(150) DEFAULT NULL,
  `fk_question_id4` int(11) DEFAULT 0,
  `answer4` varchar(150) DEFAULT NULL,
  `fk_question_id5` int(11) DEFAULT 0,
  `answer5` varchar(150) DEFAULT NULL,
  `fk_question_id6` int(11) DEFAULT 0,
  `answer6` varchar(150) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_account_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_account_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_item_id` int(11) NOT NULL,
  `username` varchar(200) DEFAULT NULL,
  `password` varchar(200) DEFAULT NULL,
  `billing_date` date DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `payment_mode` varchar(100) DEFAULT NULL,
  `linked_mobile` int(11) DEFAULT 0,
  `linked_email` int(11) DEFAULT 0,
  `linked_address` int(11) DEFAULT 0,
  `fk_ipburger_id` int(11) DEFAULT 0,
  `fk_company_house_id` int(11) DEFAULT 0,
  `fk_question_id1` int(11) DEFAULT 0,
  `answer1` varchar(150) DEFAULT NULL,
  `fk_question_id2` int(11) DEFAULT 0,
  `answer2` varchar(150) DEFAULT NULL,
  `fk_question_id3` int(11) DEFAULT 0,
  `answer3` varchar(150) DEFAULT NULL,
  `fk_question_id4` int(11) DEFAULT 0,
  `answer4` varchar(150) DEFAULT NULL,
  `fk_question_id5` int(11) DEFAULT 0,
  `answer5` varchar(150) DEFAULT NULL,
  `fk_question_id6` int(11) DEFAULT 0,
  `answer6` varchar(150) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL,
  `fk_id_bank` int(11) NOT NULL,
  `fk_id_bank_account` int(11) NOT NULL,
  `subscription_link` varchar(200) NOT NULL,
  `billing_cycle` enum('WEEKLY','MONTHLY','QUARTERLY','YEARLY','ONE_OFF') DEFAULT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  `next_due_date` date DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `vault_ref` varchar(255) DEFAULT NULL,
  `f_auto_renew` tinyint(4) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_amazon_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_amazon_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_branch` int(11) NOT NULL,
  `order_date` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_id` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `acc_group` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `po_number` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qty` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_sub_total` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `promotion` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_vat` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_net_total` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approver` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_vat_no_region` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_vat_reg_no` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inv_status` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inv_tot_amt` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inv_due_amt` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inv_issue_date` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inv_due_date` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vat_inv_no` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_date` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_amt` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_inst_type` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_identfier` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amazon_product_category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ASIN` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Item_title` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `UNSPSC` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `segment` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `family` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `class` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `commodity` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brand_code` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brand_name` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manufacturer` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `national_stock_no` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_model_no` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `part_no` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prod_cond` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comp_compliance` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `listed_ppu` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pur_ppu` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_qty` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_sub_total` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_shipping` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_promotion` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_vat` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_net_total` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `line_item_id` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disc_prog` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disc_applied_amt` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disc_applied_per` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_sib_total_vat_rate` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_gift_wrap` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_sub_total_sum` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gl_code` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deprtment` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cost_centre` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_code` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_field` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seller_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seller_cred` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seller_vat_no_country` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seller_vat_reg_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_id_branch_in_amazon_orders` (`fk_id_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_audit_main`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_audit_main` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mon` int(11) NOT NULL,
  `yr` int(11) NOT NULL,
  `d_yr_mon` date NOT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mon` (`mon`,`yr`,`fk_id_branch`),
  KEY `fk_branch_in_audit_main` (`fk_id_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_branch_params`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_branch_params` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_branch` int(11) NOT NULL,
  `fk_id_param` int(11) NOT NULL,
  `params_value` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_active` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_id_branch_in_branch_params` (`fk_id_branch`),
  KEY `fk_id_param_in_branch_params` (`fk_id_param`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_branch_profit_routes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_branch_profit_routes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_company` int(11) NOT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `profit_route` varchar(20) NOT NULL,
  `d_start` date NOT NULL,
  `d_end` date DEFAULT NULL,
  `reason` text NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_route` (`fk_id_company`,`fk_id_branch`,`d_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Dated profit route rows; one route per company x shop x month';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_branch_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_branch_reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_branch` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `fk_id_rem_type` int(11) NOT NULL,
  `e_status` enum('PENDING','RESOLVED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `d_entry` date NOT NULL,
  `reminder_text` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_branch` (`fk_id_branch`,`month`,`year`),
  KEY `fk_id_rem_type_in_t_branch_reminders` (`fk_id_rem_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL DEFAULT 1,
  `shop_no` varchar(11) NOT NULL,
  `fk_id_owner` int(11) NOT NULL,
  `fk_id_seller` int(11) NOT NULL,
  `fk_id_supervisor` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) NOT NULL,
  `shop_name` varchar(200) NOT NULL,
  `fk_id_address` int(11) DEFAULT NULL,
  `fk_id_mobile` int(11) DEFAULT NULL,
  `shop_profile_url` varchar(500) DEFAULT NULL,
  `f_authenticated` tinyint(4) NOT NULL DEFAULT 1,
  `f_active` tinyint(4) NOT NULL,
  `fk_id_company` tinyint(4) NOT NULL DEFAULT 0,
  `shop_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fk_id_payout_bank` int(11) DEFAULT NULL,
  `fk_id_payout_currency` int(11) DEFAULT NULL,
  `default_vat_pct` decimal(5,2) DEFAULT NULL,
  `fk_id_default_carrier` int(11) DEFAULT NULL,
  `fk_id_listing_manager` int(11) DEFAULT NULL,
  `seller_id` varchar(60) DEFAULT NULL,
  `fk_id_mp_profile` int(11) DEFAULT NULL,
  `fulfilment_channel` varchar(20) DEFAULT NULL,
  `fk_id_main_location` int(11) DEFAULT NULL,
  `fk_id_handler_party` int(11) DEFAULT NULL,
  `ds_min_margin_pct` varchar(4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`shop_name`),
  UNIQUE KEY `fk_id_ent_2` (`fk_id_ent`,`iso_branch`),
  KEY `fk_id_ent_in_branch` (`fk_id_ent`),
  KEY `fk_id_acc_owner_in_branch` (`fk_id_owner`),
  KEY `fk_id_acc_seller_in_branch` (`fk_id_seller`),
  KEY `idx_id_mobile_in_branch` (`fk_id_mobile`),
  KEY `fk_id_address_in_branch` (`fk_id_address`),
  KEY `fk_id_marketplace_in_branch` (`fk_id_marketplace`),
  KEY `idx_branch_company` (`fk_id_company`),
  KEY `idx_branch_owner` (`fk_id_owner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_branches_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_branches_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_branch` int(11) NOT NULL,
  `fk_id_seller` int(11) NOT NULL,
  `d_updated` datetime NOT NULL,
  `remarks` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_id_branch` (`fk_id_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_buy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_buy` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_main` int(11) NOT NULL,
  `fk_id_vendor` int(11) NOT NULL,
  `d_buy` date NOT NULL,
  `order_id` varchar(40) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `item_title` varchar(1000) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `customer_name` varchar(250) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `cust_address` varchar(800) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `no_of_items` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_id_vendor_in_buying` (`fk_id_vendor`),
  KEY `idx_id_main_in_buying` (`fk_id_main`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_carrier_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_carrier_packages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_carrier` int(11) NOT NULL,
  `package_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `weight` decimal(10,2) DEFAULT NULL,
  `dim_length` decimal(10,2) DEFAULT NULL,
  `dim_width` decimal(10,2) DEFAULT NULL,
  `dim_height` decimal(10,2) DEFAULT NULL,
  `rate1` decimal(10,2) NOT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_id_carrier_in_package` (`fk_id_carrier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_carrier_pkg_svc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_carrier_pkg_svc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pkg_svc_name` varchar(150) NOT NULL,
  `fk_id_carrier` int(11) NOT NULL,
  `fk_id_car_package` int(11) NOT NULL,
  `fk_id_car_service` int(11) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_id_carrier_in_pkg_svc` (`fk_id_carrier`),
  KEY `fk_id_pkg_in_pkg_svc` (`fk_id_car_package`),
  KEY `fk_id_svc_in_pkg_svc` (`fk_id_car_service`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_carrier_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_carrier_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_carrier` int(11) NOT NULL,
  `service_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `delivery_days` smallint(6) DEFAULT NULL,
  `max_weight_kg` decimal(8,3) DEFAULT NULL,
  `f_tracked` tinyint(4) NOT NULL DEFAULT 0,
  `f_signature` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_id_carrier_in_service` (`fk_id_carrier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_carriers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_carriers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `carrier_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `carrier_code` varchar(30) DEFAULT NULL,
  `label_source` varchar(12) NOT NULL DEFAULT 'PDF_IMPORT',
  `fk_id_api_connection` int(11) DEFAULT NULL,
  `tracking_pattern` varchar(200) DEFAULT NULL,
  `tracking_url_pattern` varchar(300) DEFAULT NULL,
  `handover_mode` varchar(12) NOT NULL DEFAULT 'COLLECTION',
  `collection_cutoff_time` time DEFAULT NULL,
  `f_manifest_required` tinyint(4) NOT NULL DEFAULT 0,
  `manifest_layout` varchar(40) DEFAULT NULL,
  `f_void_supported` tinyint(4) NOT NULL DEFAULT 0,
  `claim_days` smallint(6) DEFAULT NULL,
  `api_priority` smallint(6) DEFAULT NULL,
  `label_file_kind` varchar(6) NOT NULL DEFAULT 'PDF',
  `label_pdf_layout` varchar(20) NOT NULL DEFAULT 'ONE_PER_PAGE',
  `labels_per_page` smallint(6) DEFAULT NULL,
  `fk_id_default_label_template` int(11) DEFAULT NULL,
  `volumetric_divisor` int(11) DEFAULT NULL,
  `customer_data_rule` varchar(20) NOT NULL DEFAULT 'MATCH_ONLY',
  PRIMARY KEY (`id`),
  KEY `idx_id_ent_in_carriers` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `t_carriers` VALUES
(1,0,'Carrier X (example – PDF import)',1,'EX_CARRIER_PDF','PDF_IMPORT',NULL,NULL,NULL,'COLLECTION','16:00:00',0,NULL,0,NULL,NULL,'PDF','ONE_PER_PAGE',NULL,NULL,NULL,'MATCH_ONLY'),
(2,0,'Carrier Y (example – API, A4 sheets)',1,'EX_CARRIER_API','API',NULL,NULL,NULL,'DROP_OFF','17:00:00',0,NULL,1,NULL,NULL,'PDF','A4_MULTI',4,NULL,NULL,'MATCH_ONLY');
DROP TABLE IF EXISTS `t_controller_rights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_controller_rights` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_controller` int(11) NOT NULL,
  `fk_id_right` int(11) NOT NULL,
  `f_read` smallint(6) NOT NULL DEFAULT 1,
  `f_insert` smallint(6) NOT NULL,
  `f_update` smallint(6) NOT NULL,
  `f_delete` int(11) NOT NULL DEFAULT 0,
  `f_all` smallint(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_controller` (`fk_id_controller`,`fk_id_right`),
  KEY `idx_id_control_in_right_control` (`fk_id_controller`),
  KEY `idx_id_right_in_right_control` (`fk_id_right`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_ent_params`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_ent_params` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_prams` int(11) NOT NULL,
  `params_value` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_active` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_id_ent_in_ent_params` (`fk_id_ent`),
  KEY `fk_id_param_in_ent_params` (`fk_id_prams`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_enterprise`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_enterprise` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_module` int(11) NOT NULL,
  `iso_ent` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `ent_name` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `ent_address` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `ent_phone` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL,
  `ent_short` varchar(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_module` (`fk_id_module`,`ent_name`),
  UNIQUE KEY `iso_ent` (`iso_ent`),
  KEY `fk_id_module_in_enterprise` (`fk_id_module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_export_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_export_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `export_name` varchar(200) NOT NULL,
  `export_fields` text NOT NULL,
  `export_controller_name` varchar(100) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_import_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_import_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `import_name` varchar(600) DEFAULT NULL,
  `import_controller_name` varchar(300) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_inv_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_inv_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `entity` varchar(30) NOT NULL,
  `entity_id` int(11) NOT NULL DEFAULT 0,
  `action` varchar(40) NOT NULL,
  `details` text DEFAULT NULL,
  `fk_id_account` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_inv_number_seq`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_inv_number_seq` (
  `series` varchar(60) NOT NULL,
  `last_no` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`series`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_inv_parties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_inv_parties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `party_type` enum('ACCOUNT','COMPANY') NOT NULL DEFAULT 'ACCOUNT',
  `fk_id_account` int(11) NOT NULL DEFAULT 0,
  `fk_id_company_house` int(11) NOT NULL DEFAULT 0,
  `fk_id_account_bank` int(11) NOT NULL DEFAULT 0,
  `party_code` varchar(12) NOT NULL DEFAULT '',
  `display_name` varchar(200) NOT NULL DEFAULT '',
  `company_name` varchar(200) NOT NULL DEFAULT '',
  `company_reg_no` varchar(100) NOT NULL DEFAULT '',
  `vat_no` varchar(100) NOT NULL DEFAULT '',
  `address` text DEFAULT NULL,
  `phone` varchar(100) NOT NULL DEFAULT '',
  `email` varchar(200) NOT NULL DEFAULT '',
  `bank_name` varchar(150) NOT NULL DEFAULT '',
  `bank_account_name` varchar(150) NOT NULL DEFAULT '',
  `bank_account_no` varchar(150) NOT NULL DEFAULT '',
  `bank_sort_code` varchar(80) NOT NULL DEFAULT '',
  `bank_iban` varchar(150) NOT NULL DEFAULT '',
  `bank_swift` varchar(150) NOT NULL DEFAULT '',
  `payment_terms_days` int(11) NOT NULL DEFAULT 14,
  `f_is_sbs` tinyint(4) NOT NULL DEFAULT 0 COMMENT '1 = Safe Buy Stores Ltd (default handler / 3PL)',
  `f_portal_access` tinyint(4) NOT NULL DEFAULT 0 COMMENT '1 = investor may use the portal',
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_party_account` (`fk_id_ent`,`fk_id_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_inv_period_investors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_inv_period_investors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_period` int(11) NOT NULL,
  `fk_id_investor_account` int(11) NOT NULL COMMENT 't_account.id from t_product_investors (0 = no investor)',
  `sales_count` int(11) NOT NULL DEFAULT 0,
  `units` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'fractional when a product has several investors',
  `revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `capital` decimal(12,2) NOT NULL DEFAULT 0.00,
  `actual_cost` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'hidden',
  `direct_costs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_adjustment` decimal(12,2) NOT NULL DEFAULT 0.00,
  `allocated_costs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `profit_before_split` decimal(12,2) NOT NULL DEFAULT 0.00,
  `weight_pct` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `share_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `f_self` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'investor is also the account owner',
  PRIMARY KEY (`id`),
  KEY `idx_pi_period` (`fk_id_period`),
  KEY `idx_pi_investor` (`fk_id_investor_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_inv_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_inv_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_import` int(11) NOT NULL COMMENT 't_sale_imports.id (shop + month)',
  `fk_id_marketplace` int(11) NOT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `d_from` date NOT NULL COMMENT 'first day of the sales month',
  `d_to` date NOT NULL COMMENT 'last day of the sales month',
  `status` enum('CONFIRMED','UNCONFIRMED') NOT NULL DEFAULT 'CONFIRMED' COMMENT 'CONFIRMED = settled, UNCONFIRMED = reopened',
  `sales_count` int(11) NOT NULL DEFAULT 0,
  `units` int(11) NOT NULL DEFAULT 0,
  `revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `capital` decimal(12,2) NOT NULL DEFAULT 0.00,
  `actual_cost` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'landing_price x qty (hidden)',
  `marketplace_fees` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 't_sale_imports.marketplace_fee',
  `shipping` decimal(12,2) NOT NULL DEFAULT 0.00,
  `packing_material` decimal(12,2) NOT NULL DEFAULT 0.00,
  `packing_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `storage_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `extra_3pl` decimal(12,2) NOT NULL DEFAULT 0.00,
  `extra_3pl_note` varchar(255) NOT NULL DEFAULT '',
  `advertising` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_expenses` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_expenses_note` varchar(255) NOT NULL DEFAULT '',
  `refund_adjustment` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'refunds beyond product/carrier/packing parts',
  `va_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `owner_fixed` decimal(12,2) NOT NULL DEFAULT 0.00,
  `handler_fixed` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gross_profit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `final_net_profit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loss_bf` decimal(12,2) NOT NULL DEFAULT 0.00,
  `distributable` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loss_cf` decimal(12,2) NOT NULL DEFAULT 0.00,
  `investor_pct` decimal(6,2) NOT NULL DEFAULT 0.00,
  `owner_pct` decimal(6,2) NOT NULL DEFAULT 0.00,
  `va_pct` decimal(6,2) NOT NULL DEFAULT 0.00,
  `handler_pct` decimal(6,2) NOT NULL DEFAULT 0.00,
  `owner_mode` varchar(20) NOT NULL DEFAULT 'SHARE',
  `va_mode` varchar(20) NOT NULL DEFAULT 'SHARE',
  `handler_mode` varchar(20) NOT NULL DEFAULT 'SHARE',
  `investor_pool` decimal(12,2) NOT NULL DEFAULT 0.00,
  `owner_share` decimal(12,2) NOT NULL DEFAULT 0.00,
  `va_share` decimal(12,2) NOT NULL DEFAULT 0.00,
  `handler_share` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fk_id_split` int(11) NOT NULL DEFAULT 0,
  `fk_owner_account` int(11) NOT NULL DEFAULT 0,
  `fk_va_account` int(11) NOT NULL DEFAULT 0,
  `fk_handler_party` int(11) NOT NULL DEFAULT 0,
  `calc_json` mediumtext DEFAULT NULL,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `confirmed_by` int(11) NOT NULL DEFAULT 0,
  `confirmed_at` datetime DEFAULT NULL,
  `unconfirmed_by` int(11) NOT NULL DEFAULT 0,
  `unconfirmed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_period_import` (`fk_id_import`),
  KEY `idx_period_lookup` (`fk_id_ent`,`fk_id_branch`,`fk_id_marketplace`,`d_from`,`d_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_inv_profit_splits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_inv_profit_splits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_branch` int(11) NOT NULL DEFAULT 0 COMMENT '0 = default for all shops',
  `d_effective_from` date NOT NULL,
  `investor_pct` decimal(6,2) NOT NULL DEFAULT 40.00,
  `owner_pct` decimal(6,2) NOT NULL DEFAULT 20.00,
  `va_pct` decimal(6,2) NOT NULL DEFAULT 15.00,
  `handler_pct` decimal(6,2) NOT NULL DEFAULT 25.00,
  `owner_mode` enum('SHARE','FIXED','FIXED_SHARE') NOT NULL DEFAULT 'SHARE',
  `owner_fixed` decimal(10,2) NOT NULL DEFAULT 0.00,
  `va_mode` enum('SHARE','SALARY','SALARY_SHARE') NOT NULL DEFAULT 'SHARE',
  `va_salary_override` decimal(10,2) DEFAULT NULL COMMENT 'NULL = take from VA job history',
  `handler_mode` enum('SHARE','FIXED','FIXED_SHARE') NOT NULL DEFAULT 'SHARE',
  `handler_fixed` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fk_id_handler_party` int(11) NOT NULL DEFAULT 0 COMMENT '0 = Safe Buy Stores Ltd',
  `notes` varchar(500) NOT NULL DEFAULT '',
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_split_lookup` (`fk_id_ent`,`fk_id_branch`,`d_effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_invoice_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_invoice_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_invoice` int(11) NOT NULL,
  `line_no` int(11) NOT NULL DEFAULT 1,
  `line_group` enum('ITEM','RETURN','SHARE','FEE','COST','INFO') NOT NULL DEFAULT 'ITEM',
  `description` varchar(500) NOT NULL DEFAULT '',
  `sku` varchar(100) NOT NULL DEFAULT '',
  `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_line_invoice` (`fk_id_invoice`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_invoice_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_invoice_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_invoice` int(11) NOT NULL,
  `d_payment` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` varchar(50) NOT NULL DEFAULT '',
  `received_into` varchar(200) NOT NULL DEFAULT '',
  `reference` varchar(200) NOT NULL DEFAULT '',
  `notes` varchar(500) NOT NULL DEFAULT '',
  `proof_file` varchar(255) NOT NULL DEFAULT '',
  `f_deleted` tinyint(4) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `deleted_by` int(11) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `fk_id_bank_line` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pay_invoice` (`fk_id_invoice`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_invoice_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_invoice_settlements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_invoice` int(11) NOT NULL,
  `d_settled` date NOT NULL,
  `settle_method` varchar(20) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `fk_id_bank_line` int(11) DEFAULT NULL,
  `fk_id_credit_note` int(11) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_inv_settle` (`fk_id_invoice`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='How an invoice was settled';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_period` int(11) NOT NULL DEFAULT 0,
  `invoice_no` varchar(60) NOT NULL,
  `invoice_type` enum('INVESTOR','SELF_SETTLEMENT','OWNER_STATEMENT','VA','SBS_3PL','SBS_HANDLER','CREDIT_NOTE') NOT NULL,
  `fk_issuer_party` int(11) NOT NULL DEFAULT 0,
  `fk_recipient_party` int(11) NOT NULL DEFAULT 0,
  `fk_id_investor_account` int(11) NOT NULL DEFAULT 0,
  `issuer_json` text DEFAULT NULL,
  `recipient_json` text DEFAULT NULL,
  `d_invoice` date NOT NULL,
  `d_due` date DEFAULT NULL,
  `reference` varchar(255) NOT NULL DEFAULT '',
  `currency` varchar(3) DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('UNPAID','PART_PAID','PAID','CANCELLED','NOT_REQUIRED','CREDITED') NOT NULL DEFAULT 'UNPAID',
  `fk_id_credit_of` int(11) NOT NULL DEFAULT 0,
  `cancel_reason` varchar(255) NOT NULL DEFAULT '',
  `cancelled_by` int(11) NOT NULL DEFAULT 0,
  `cancelled_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `sent_to` varchar(200) NOT NULL DEFAULT '',
  `sent_by` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `fk_id_credit_note` int(11) DEFAULT NULL,
  `credit_direction` char(3) DEFAULT NULL,
  `credit_use` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_no` (`fk_id_ent`,`invoice_no`),
  KEY `idx_inv_period` (`fk_id_period`),
  KEY `idx_inv_investor` (`fk_id_investor_account`),
  KEY `idx_inv_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_listing_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_listing_components` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_listing` int(11) NOT NULL,
  `fk_id_product` int(11) NOT NULL,
  `qty_per_unit` smallint(6) NOT NULL DEFAULT 1,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_listing_comp` (`fk_id_listing`,`fk_id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Bundle listing components (R-H-19)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_mobile_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_mobile_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `mobile_name` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `mobile_code` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `tot_slots` int(11) NOT NULL,
  `f_active` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_id_ent_in_mobile_devices` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_packings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_packings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `packing_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `packing_rate` decimal(10,2) NOT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `fk_ent_in_packings` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_party_profile`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_party_profile` (
  `fk_id_account` int(11) NOT NULL,
  `company_name` varchar(200) DEFAULT NULL,
  `company_reg_no` varchar(100) DEFAULT NULL,
  `vat_no` varchar(100) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `website` varchar(300) DEFAULT NULL,
  `main_email` varchar(200) DEFAULT NULL,
  `main_phone` varchar(100) DEFAULT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  `fk_id_payment_term` int(11) DEFAULT NULL,
  `fk_id_incoterm` int(11) DEFAULT NULL,
  `lead_time_days` int(11) DEFAULT NULL,
  `min_order_value` decimal(14,2) DEFAULT NULL,
  `rating` tinyint(4) DEFAULT NULL,
  `fk_id_account_bank` int(11) DEFAULT NULL,
  `vault_ref` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`fk_id_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_inventory_change_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_inventory_change_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_user_id` int(11) DEFAULT NULL,
  `changed_by` varchar(255) DEFAULT NULL,
  `changed_fields` longtext DEFAULT NULL,
  `old_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `new_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `old_raw_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `new_raw_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `change_type` enum('price_change','general_edit') NOT NULL DEFAULT 'general_edit',
  `fk_id_reason` int(11) DEFAULT NULL,
  `from_date` date DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `fk_product_id` int(11) DEFAULT NULL,
  `fk_id_marketplace` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `carrier_rate` decimal(10,2) DEFAULT NULL,
  `fk_id_packing` int(11) DEFAULT NULL,
  `packing_rate` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fk_product_id` (`fk_product_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_product_pricematch` (`fk_product_id`,`change_type`,`new_price`),
  KEY `idx_product_period` (`fk_product_id`,`change_type`,`from_date`,`to_date`),
  KEY `idx_product_mp_period` (`fk_product_id`,`fk_id_marketplace`,`change_type`,`from_date`),
  KEY `idx_product_mp_price` (`fk_product_id`,`fk_id_marketplace`,`change_type`,`new_price`),
  KEY `idx_pcl_period_lookup` (`fk_product_id`,`fk_id_marketplace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_inventory_child_change_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_inventory_child_change_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_user_id` int(11) DEFAULT NULL,
  `changed_by` varchar(255) DEFAULT NULL,
  `changed_fields` longtext DEFAULT NULL,
  `fk_product_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fk_product_id` (`fk_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_investors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_investors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_product` int(11) NOT NULL COMMENT 'FK -> t_product_vendors.id',
  `fk_id_account` int(11) NOT NULL COMMENT 'FK -> t_account.id (investor, role 34)',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_investor` (`fk_id_product`,`fk_id_account`),
  KEY `idx_account` (`fk_id_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_listings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_listings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_product` int(11) NOT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL,
  `item_id` varchar(60) DEFAULT NULL,
  `asin` varchar(20) DEFAULT NULL,
  `marketplace_sku` varchar(100) DEFAULT NULL,
  `fnsku` varchar(20) DEFAULT NULL,
  `listing_title` varchar(255) DEFAULT NULL,
  `status` enum('DRAFT','ACTIVE','INACTIVE','SUPPRESSED','STRANDED','BLOCKED','CLOSED') NOT NULL DEFAULT 'DRAFT',
  `fulfilment` enum('FBM','FBA','DROPSHIP','3PL') NOT NULL DEFAULT 'FBM',
  `price` decimal(12,2) DEFAULT NULL,
  `min_price` decimal(12,2) DEFAULT NULL,
  `max_price` decimal(12,2) DEFAULT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  `d_listed` date DEFAULT NULL,
  `fk_id_listing_manager` int(11) DEFAULT NULL,
  `last_report_at` datetime DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `pack_qty` smallint(6) NOT NULL DEFAULT 1,
  `f_bundle` tinyint(4) NOT NULL DEFAULT 0,
  `stock_buffer_override` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item` (`fk_id_branch`,`item_id`),
  UNIQUE KEY `uq_asin` (`fk_id_branch`,`asin`),
  KEY `ix_prod` (`fk_id_product`),
  KEY `ix_msku` (`fk_id_branch`,`marketplace_sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_product` int(11) NOT NULL,
  `fk_id_location` int(11) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fk_id_owner_seg` int(11) NOT NULL DEFAULT 0,
  `home_role` varchar(10) NOT NULL DEFAULT 'NONE',
  `min_qty` int(11) DEFAULT NULL,
  `max_qty` int(11) DEFAULT NULL,
  `last_counted_at` datetime DEFAULT NULL,
  `last_move_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_location` (`fk_id_product`,`fk_id_location`),
  UNIQUE KEY `uq_prodloc_owner` (`fk_id_product`,`fk_id_location`,`fk_id_owner_seg`),
  KEY `idx_location` (`fk_id_location`),
  KEY `ix_prodloc_loc` (`fk_id_location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_marketplace_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_marketplace_prices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_product` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL,
  `raw_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sale_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `list_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `marketplace_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_marketplace` (`fk_id_product`,`fk_id_marketplace`),
  KEY `idx_marketplace` (`fk_id_marketplace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_marketplace_shipping`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_marketplace_shipping` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_product` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL,
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL COMMENT 'virtual id from t_carrier_pkg_svc',
  `fk_id_packing` int(11) DEFAULT NULL,
  `packing_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `storage_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_marketplace` (`fk_id_product`,`fk_id_marketplace`),
  KEY `idx_marketplace` (`fk_id_marketplace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_marketplace_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_marketplace_values` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_product` int(11) NOT NULL COMMENT 'FK -> t_product_vendors.id',
  `fk_id_marketplace` int(11) NOT NULL COMMENT 'FK -> cat_marketplaces.id',
  `fk_id_field` int(11) NOT NULL COMMENT 'FK -> cat_marketplace_fields.id',
  `field_value` text DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_field` (`fk_id_product`,`fk_id_field`),
  KEY `idx_product_marketplace` (`fk_id_product`,`fk_id_marketplace`),
  KEY `idx_marketplace` (`fk_id_marketplace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_purchases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_product` int(11) NOT NULL,
  `fk_id_supplier` int(11) NOT NULL COMMENT 'FK -> t_account.id (type 21)',
  `invoice_no` varchar(100) DEFAULT NULL,
  `batch_no` varchar(50) DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `shipping_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `other_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'qty*unit + shipping + other',
  `payment_status` enum('Paid','Partial','Unpaid') NOT NULL DEFAULT 'Unpaid',
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `f_stock_added` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = qty was added to product stock',
  `notes` text DEFAULT NULL,
  `f_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_batch` (`fk_id_product`,`batch_no`),
  KEY `idx_product` (`fk_id_product`),
  KEY `idx_supplier` (`fk_id_supplier`),
  KEY `idx_purchase_date` (`purchase_date`),
  KEY `idx_purch_product_active` (`fk_id_product`,`f_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_sale_change_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_sale_change_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_user_id` int(11) DEFAULT NULL,
  `changed_by` varchar(255) DEFAULT NULL,
  `changed_fields` longtext DEFAULT NULL,
  `fk_product_id` int(11) DEFAULT NULL,
  `product_profit` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fk_product_id` (`fk_product_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_vendors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) NOT NULL,
  `product_name_vendor` varchar(255) NOT NULL,
  `ean` varchar(20) DEFAULT NULL,
  `fk_id_product_type` int(11) NOT NULL,
  `fk_id_brand` int(11) DEFAULT NULL,
  `fk_id_product_condition` int(11) NOT NULL DEFAULT 1,
  `fk_id_product_trend` int(11) DEFAULT NULL,
  `fk_id_product_vendor_self` int(11) DEFAULT NULL,
  `variant` varchar(70) DEFAULT NULL,
  `d_created` date DEFAULT NULL,
  `fk_id_country_make` int(11) DEFAULT NULL,
  `weight` varchar(50) DEFAULT NULL,
  `dimensions` varchar(15) DEFAULT NULL,
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `fk_id_packing` int(11) DEFAULT NULL,
  `url_ebay` varchar(500) DEFAULT NULL,
  `ebay_fee` decimal(10,2) DEFAULT NULL,
  `sale_price_with_vat` decimal(10,2) DEFAULT NULL,
  `sale_price` decimal(10,2) NOT NULL,
  `list_price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL,
  `f_out_of_stock` tinyint(4) NOT NULL DEFAULT 0,
  `min_level` int(11) NOT NULL,
  `f_alert_min_level` tinyint(1) NOT NULL,
  `href_product` varchar(255) DEFAULT NULL,
  `SKU_VENDOR` varchar(50) NOT NULL,
  `opc` varchar(50) DEFAULT NULL,
  `upc` varchar(50) DEFAULT NULL,
  `fk_id_account_added_by` int(11) DEFAULT NULL,
  `fk_id_account_updated_by` int(11) DEFAULT NULL,
  `f_active` tinyint(1) NOT NULL,
  `carrier` varchar(500) NOT NULL,
  `f_tag1` tinyint(4) NOT NULL DEFAULT 0,
  `f_tag2` tinyint(4) NOT NULL DEFAULT 0,
  `f_tag3` tinyint(4) NOT NULL DEFAULT 0,
  `d_product_expiry_date` date DEFAULT '0000-00-00',
  `d_purchase_date` date DEFAULT NULL,
  `fk_id_role` int(11) DEFAULT 0,
  `fk_id_role_account` int(11) DEFAULT 0,
  `fk_id_vendor_inventory` int(11) DEFAULT 0,
  `f_parent` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=single,2=variant parent, 3=variant child',
  `f_id_parent` int(11) NOT NULL DEFAULT 0,
  `f_color` varchar(50) DEFAULT NULL,
  `f_size` varchar(20) DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `f_group_name` varchar(255) DEFAULT NULL,
  `product_img` varchar(100) DEFAULT NULL,
  `hs_code` varchar(255) NOT NULL,
  `product_description` mediumtext DEFAULT NULL,
  `fk_id_role2` int(11) DEFAULT 0,
  `fk_id_role_account2` int(11) DEFAULT 0,
  `fk_id_role3` int(11) DEFAULT 0,
  `fk_id_role_account3` int(11) DEFAULT 0,
  `fk_id_branch` int(11) DEFAULT 0,
  `raw_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `landing_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vendor_reference` varchar(100) DEFAULT NULL COMMENT 'vendor own SKU/ref for this product',
  `moq` int(11) NOT NULL DEFAULT 0 COMMENT 'minimum order quantity',
  `lead_time_days` int(11) NOT NULL DEFAULT 0,
  `vendor_notes` text DEFAULT NULL,
  `e_product_type` enum('pool','private') NOT NULL DEFAULT 'private',
  `unit_net_weight` decimal(15,4) DEFAULT NULL,
  `unit_gross_weight` decimal(15,4) DEFAULT NULL,
  `material` varchar(255) DEFAULT NULL,
  `units_per_carton` decimal(15,4) DEFAULT NULL,
  `carton_l` decimal(15,4) DEFAULT NULL,
  `carton_w` decimal(15,4) DEFAULT NULL,
  `carton_h` decimal(15,4) DEFAULT NULL,
  `carton_gross_weight` decimal(15,4) DEFAULT NULL,
  `carton_cbm` decimal(15,6) DEFAULT NULL,
  `production_lead_days` int(11) DEFAULT NULL,
  `shipping_lead_days` int(11) DEFAULT NULL,
  `safety_days` int(11) DEFAULT NULL,
  `reorder_point` int(11) DEFAULT NULL,
  `reorder_qty` int(11) DEFAULT NULL,
  `fk_id_default_supplier` int(11) DEFAULT NULL,
  `cost_method` enum('FIFO','WAC') NOT NULL DEFAULT 'FIFO',
  `fk_id_merged_into` int(11) DEFAULT NULL,
  `icc_status` enum('NONE','GREEN','YELLOW','RED') DEFAULT NULL,
  `icc_order_by` date DEFAULT NULL,
  `icc_stock_out` date DEFAULT NULL,
  `icc_order_qty` int(11) DEFAULT NULL,
  `icc_cycle_days` smallint(6) DEFAULT NULL,
  `icc_run_at` datetime DEFAULT NULL,
  `fk_id_origin_country` int(11) DEFAULT NULL,
  `tag_ids` varchar(500) DEFAULT NULL,
  `f_contains_battery` tinyint(4) NOT NULL DEFAULT 0,
  `f_dangerous_goods` tinyint(4) NOT NULL DEFAULT 0,
  `f_age_restricted` tinyint(4) NOT NULL DEFAULT 0,
  `target_margin_pct` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`SKU_VENDOR`),
  UNIQUE KEY `fk_id_product` (`fk_id_product_main`,`fk_id_vendor`),
  KEY `fk_id_product_in_prod_vend` (`fk_id_product_main`),
  KEY `fk_id_vendor_in_prod_vend` (`fk_id_vendor`),
  KEY `idx_id_ent_in_prod_vendor` (`fk_id_ent`),
  KEY `idx_sku_in_vendor_product` (`SKU_VENDOR`),
  KEY `idx_id_account_in_vendor_product` (`fk_id_account_added_by`),
  KEY `idx_id_acc_in_prod_vendor` (`fk_id_account_updated_by`),
  KEY `idx_id_type_prod_in_vendor_prod` (`fk_id_product_type`),
  KEY `fk_id_packing_in_prod_vend` (`fk_id_packing`),
  KEY `fk_id_trend_in_prod_venodr` (`fk_id_product_trend`),
  KEY `fk_id_pkg_svc_in_prod_vendor` (`fk_id_car_pkg_svc`),
  KEY `idx_f_id_parent` (`f_id_parent`),
  KEY `idx_parent_active` (`f_parent`,`f_active`),
  KEY `idx_fk_id_branch` (`fk_id_branch`),
  KEY `idx_fk_id_vendor_inventory` (`fk_id_vendor_inventory`),
  KEY `idx_f_out_of_stock` (`f_out_of_stock`),
  KEY `idx_brand` (`fk_id_brand`),
  KEY `idx_fk_id_brand` (`fk_id_brand`),
  KEY `idx_listing_main` (`f_parent`,`f_active`,`id`),
  KEY `idx_listing_type` (`fk_id_product_type`),
  KEY `idx_listing_brand` (`fk_id_brand`),
  KEY `idx_listing_parentref` (`f_id_parent`,`f_parent`,`f_active`),
  KEY `idx_e_product_type` (`e_product_type`),
  KEY `idx_product_condition` (`fk_id_product_condition`),
  KEY `idx_trend` (`fk_id_product_trend`),
  KEY `idx_country` (`fk_id_country_make`),
  KEY `idx_ean` (`ean`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_vendors_12_7_23`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_vendors_12_7_23` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) NOT NULL,
  `product_name_vendor` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `ean` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_product_type` int(11) NOT NULL,
  `fk_id_product_trend` int(11) DEFAULT NULL,
  `fk_id_product_vendor_self` int(11) DEFAULT NULL,
  `variant` varchar(70) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `d_created` date DEFAULT NULL,
  `fk_id_country_make` int(11) DEFAULT NULL,
  `weight` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `dimensions` varchar(15) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `fk_id_packing` int(11) DEFAULT NULL,
  `url_ebay` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `ebay_fee` decimal(10,2) DEFAULT NULL,
  `sale_price_with_vat` decimal(10,2) DEFAULT NULL,
  `sale_price` decimal(10,2) NOT NULL,
  `list_price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL,
  `f_out_of_stock` tinyint(4) NOT NULL DEFAULT 0,
  `min_level` int(11) NOT NULL,
  `f_alert_min_level` tinyint(1) NOT NULL,
  `href_product` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `SKU_VENDOR` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `opc` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `upc` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_account_added_by` int(11) DEFAULT NULL,
  `fk_id_account_updated_by` int(11) DEFAULT NULL,
  `f_active` tinyint(1) NOT NULL,
  `carrier` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_tag1` tinyint(4) NOT NULL DEFAULT 0,
  `f_tag2` tinyint(4) NOT NULL DEFAULT 0,
  `f_tag3` tinyint(4) NOT NULL DEFAULT 0,
  `d_product_expiry_date` date DEFAULT '0000-00-00',
  `fk_id_role` int(11) NOT NULL DEFAULT 0,
  `fk_id_role_account` int(11) NOT NULL DEFAULT 0,
  `fk_id_vendor_inventory` int(11) NOT NULL DEFAULT 0,
  `f_parent` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=single,2=variant parent, 3=variant child',
  `f_id_parent` int(11) NOT NULL DEFAULT 0,
  `f_color` varchar(50) DEFAULT NULL,
  `f_size` varchar(20) DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `f_group_name` varchar(255) DEFAULT NULL,
  `product_img` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`SKU_VENDOR`),
  UNIQUE KEY `fk_id_product` (`fk_id_product_main`,`fk_id_vendor`),
  KEY `fk_id_product_in_prod_vend` (`fk_id_product_main`),
  KEY `fk_id_vendor_in_prod_vend` (`fk_id_vendor`),
  KEY `idx_id_ent_in_prod_vendor` (`fk_id_ent`),
  KEY `idx_sku_in_vendor_product` (`SKU_VENDOR`),
  KEY `idx_id_account_in_vendor_product` (`fk_id_account_added_by`),
  KEY `idx_id_acc_in_prod_vendor` (`fk_id_account_updated_by`),
  KEY `idx_id_type_prod_in_vendor_prod` (`fk_id_product_type`),
  KEY `fk_id_packing_in_prod_vend` (`fk_id_packing`),
  KEY `fk_id_trend_in_prod_venodr` (`fk_id_product_trend`),
  KEY `fk_id_pkg_svc_in_prod_vendor` (`fk_id_car_pkg_svc`),
  KEY `idx_fk_id_vendor` (`fk_id_vendor`),
  KEY `idx_sku_vendor_in_tpv` (`SKU_VENDOR`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_vendors_2025`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_vendors_2025` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) NOT NULL,
  `product_name_vendor` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `ean` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_product_type` int(11) NOT NULL,
  `fk_id_product_trend` int(11) DEFAULT NULL,
  `fk_id_product_vendor_self` int(11) DEFAULT NULL,
  `variant` varchar(70) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `d_created` date DEFAULT NULL,
  `fk_id_country_make` int(11) DEFAULT NULL,
  `weight` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `dimensions` varchar(15) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `fk_id_packing` int(11) DEFAULT NULL,
  `url_ebay` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `ebay_fee` decimal(10,2) DEFAULT NULL,
  `sale_price_with_vat` decimal(10,2) DEFAULT NULL,
  `sale_price` decimal(10,2) NOT NULL,
  `list_price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL,
  `f_out_of_stock` tinyint(4) NOT NULL DEFAULT 0,
  `min_level` int(11) NOT NULL,
  `f_alert_min_level` tinyint(1) NOT NULL,
  `href_product` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `SKU_VENDOR` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `opc` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `upc` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_account_added_by` int(11) DEFAULT NULL,
  `fk_id_account_updated_by` int(11) DEFAULT NULL,
  `f_active` tinyint(1) NOT NULL,
  `carrier` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_tag1` tinyint(4) NOT NULL DEFAULT 0,
  `f_tag2` tinyint(4) NOT NULL DEFAULT 0,
  `f_tag3` tinyint(4) NOT NULL DEFAULT 0,
  `d_product_expiry_date` date DEFAULT '0000-00-00',
  `fk_id_role` int(11) NOT NULL DEFAULT 0,
  `fk_id_role_account` int(11) NOT NULL DEFAULT 0,
  `fk_id_vendor_inventory` int(11) NOT NULL DEFAULT 0,
  `f_parent` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=single,2=variant parent, 3=variant child',
  `f_id_parent` int(11) NOT NULL DEFAULT 0,
  `f_color` varchar(50) DEFAULT NULL,
  `f_size` varchar(20) DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `f_group_name` varchar(255) DEFAULT NULL,
  `product_img` varchar(100) DEFAULT NULL,
  `hs_code` varchar(255) NOT NULL,
  `product_description` text DEFAULT NULL,
  `fk_id_role2` int(11) NOT NULL DEFAULT 0,
  `fk_id_role_account2` int(11) NOT NULL DEFAULT 0,
  `fk_id_role3` int(11) NOT NULL DEFAULT 0,
  `fk_id_role_account3` int(11) NOT NULL DEFAULT 0,
  `fk_id_branch` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`SKU_VENDOR`),
  UNIQUE KEY `fk_id_product` (`fk_id_product_main`,`fk_id_vendor`),
  KEY `fk_id_product_in_prod_vend` (`fk_id_product_main`),
  KEY `fk_id_vendor_in_prod_vend` (`fk_id_vendor`),
  KEY `idx_id_ent_in_prod_vendor` (`fk_id_ent`),
  KEY `idx_sku_in_vendor_product` (`SKU_VENDOR`),
  KEY `idx_id_account_in_vendor_product` (`fk_id_account_added_by`),
  KEY `idx_id_acc_in_prod_vendor` (`fk_id_account_updated_by`),
  KEY `idx_id_type_prod_in_vendor_prod` (`fk_id_product_type`),
  KEY `fk_id_packing_in_prod_vend` (`fk_id_packing`),
  KEY `fk_id_trend_in_prod_venodr` (`fk_id_product_trend`),
  KEY `fk_id_pkg_svc_in_prod_vendor` (`fk_id_car_pkg_svc`),
  KEY `idx_fk_id_vendor` (`fk_id_vendor`),
  KEY `idx_sku_vendor_in_tpv` (`SKU_VENDOR`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_vendors_2026`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_vendors_2026` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) NOT NULL,
  `product_name_vendor` varchar(255) NOT NULL,
  `ean` varchar(20) DEFAULT NULL,
  `fk_id_product_type` int(11) NOT NULL,
  `fk_id_brand` int(11) DEFAULT NULL,
  `fk_id_product_trend` int(11) DEFAULT NULL,
  `fk_id_product_vendor_self` int(11) DEFAULT NULL,
  `variant` varchar(70) DEFAULT NULL,
  `d_created` date DEFAULT NULL,
  `fk_id_country_make` int(11) DEFAULT NULL,
  `weight` varchar(50) DEFAULT NULL,
  `dimensions` varchar(15) DEFAULT NULL,
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `fk_id_packing` int(11) DEFAULT NULL,
  `url_ebay` varchar(500) DEFAULT NULL,
  `ebay_fee` decimal(10,2) DEFAULT NULL,
  `sale_price_with_vat` decimal(10,2) DEFAULT NULL,
  `sale_price` decimal(10,2) NOT NULL,
  `list_price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL,
  `f_out_of_stock` tinyint(4) NOT NULL DEFAULT 0,
  `min_level` int(11) NOT NULL,
  `f_alert_min_level` tinyint(1) NOT NULL,
  `href_product` varchar(255) DEFAULT NULL,
  `SKU_VENDOR` varchar(50) NOT NULL,
  `opc` varchar(50) DEFAULT NULL,
  `upc` varchar(50) DEFAULT NULL,
  `fk_id_account_added_by` int(11) DEFAULT NULL,
  `fk_id_account_updated_by` int(11) DEFAULT NULL,
  `f_active` tinyint(1) NOT NULL,
  `carrier` varchar(500) NOT NULL,
  `f_tag1` tinyint(4) NOT NULL DEFAULT 0,
  `f_tag2` tinyint(4) NOT NULL DEFAULT 0,
  `f_tag3` tinyint(4) NOT NULL DEFAULT 0,
  `d_product_expiry_date` date DEFAULT '0000-00-00',
  `d_purchase_date` date DEFAULT NULL,
  `fk_id_role` int(11) DEFAULT 0,
  `fk_id_role_account` int(11) DEFAULT 0,
  `fk_id_vendor_inventory` int(11) DEFAULT 0,
  `f_parent` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=single,2=variant parent, 3=variant child',
  `f_id_parent` int(11) NOT NULL DEFAULT 0,
  `f_color` varchar(50) DEFAULT NULL,
  `f_size` varchar(20) DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `f_group_name` varchar(255) DEFAULT NULL,
  `product_img` varchar(100) DEFAULT NULL,
  `hs_code` varchar(255) NOT NULL,
  `product_description` mediumtext DEFAULT NULL,
  `fk_id_role2` int(11) DEFAULT 0,
  `fk_id_role_account2` int(11) DEFAULT 0,
  `fk_id_role3` int(11) DEFAULT 0,
  `fk_id_role_account3` int(11) DEFAULT 0,
  `fk_id_branch` int(11) DEFAULT 0,
  `raw_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `landing_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vendor_reference` varchar(100) DEFAULT NULL COMMENT 'vendor own SKU/ref for this product',
  `moq` int(11) NOT NULL DEFAULT 0 COMMENT 'minimum order quantity',
  `lead_time_days` int(11) NOT NULL DEFAULT 0,
  `vendor_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`SKU_VENDOR`),
  UNIQUE KEY `fk_id_product` (`fk_id_product_main`,`fk_id_vendor`),
  KEY `fk_id_product_in_prod_vend` (`fk_id_product_main`),
  KEY `fk_id_vendor_in_prod_vend` (`fk_id_vendor`),
  KEY `idx_id_ent_in_prod_vendor` (`fk_id_ent`),
  KEY `idx_sku_in_vendor_product` (`SKU_VENDOR`),
  KEY `idx_id_account_in_vendor_product` (`fk_id_account_added_by`),
  KEY `idx_id_acc_in_prod_vendor` (`fk_id_account_updated_by`),
  KEY `idx_id_type_prod_in_vendor_prod` (`fk_id_product_type`),
  KEY `fk_id_packing_in_prod_vend` (`fk_id_packing`),
  KEY `fk_id_trend_in_prod_venodr` (`fk_id_product_trend`),
  KEY `fk_id_pkg_svc_in_prod_vendor` (`fk_id_car_pkg_svc`),
  KEY `idx_f_id_parent` (`f_id_parent`),
  KEY `idx_parent_active` (`f_parent`,`f_active`),
  KEY `idx_fk_id_branch` (`fk_id_branch`),
  KEY `idx_fk_id_vendor_inventory` (`fk_id_vendor_inventory`),
  KEY `idx_f_out_of_stock` (`f_out_of_stock`),
  KEY `idx_brand` (`fk_id_brand`),
  KEY `idx_fk_id_brand` (`fk_id_brand`),
  KEY `idx_listing_main` (`f_parent`,`f_active`,`id`),
  KEY `idx_listing_type` (`fk_id_product_type`),
  KEY `idx_listing_brand` (`fk_id_brand`),
  KEY `idx_listing_parentref` (`f_id_parent`,`f_parent`,`f_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_vendors_backup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_vendors_backup` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) NOT NULL,
  `product_name_vendor` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `ean` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_product_type` int(11) NOT NULL,
  `fk_id_product_trend` int(11) DEFAULT NULL,
  `fk_id_product_vendor_self` int(11) DEFAULT NULL,
  `variant` varchar(70) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `d_created` date DEFAULT NULL,
  `fk_id_country_make` int(11) DEFAULT NULL,
  `weight` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `dimensions` varchar(15) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `fk_id_packing` int(11) DEFAULT NULL,
  `url_ebay` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `ebay_fee` decimal(10,2) DEFAULT NULL,
  `sale_price_with_vat` decimal(10,2) DEFAULT NULL,
  `sale_price` decimal(10,2) NOT NULL,
  `list_price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL,
  `f_out_of_stock` tinyint(4) NOT NULL DEFAULT 0,
  `min_level` int(11) NOT NULL,
  `f_alert_min_level` tinyint(1) NOT NULL,
  `href_product` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `SKU_VENDOR` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `opc` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `upc` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_account_added_by` int(11) DEFAULT NULL,
  `fk_id_account_updated_by` int(11) DEFAULT NULL,
  `f_active` tinyint(1) NOT NULL,
  `carrier` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_tag1` tinyint(4) NOT NULL DEFAULT 0,
  `f_tag2` tinyint(4) NOT NULL DEFAULT 0,
  `f_tag3` tinyint(4) NOT NULL DEFAULT 0,
  `d_product_expiry_date` date DEFAULT '0000-00-00',
  `fk_id_role` int(11) NOT NULL DEFAULT 0,
  `fk_id_role_account` int(11) NOT NULL DEFAULT 0,
  `fk_id_vendor_inventory` int(11) NOT NULL DEFAULT 0,
  `f_parent` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=single,2=variant parent, 3=variant child',
  `f_id_parent` int(11) NOT NULL DEFAULT 0,
  `f_color` varchar(50) DEFAULT NULL,
  `f_size` varchar(20) DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `f_group_name` varchar(255) DEFAULT NULL,
  `product_img` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`SKU_VENDOR`),
  UNIQUE KEY `fk_id_product` (`fk_id_product_main`,`fk_id_vendor`),
  KEY `fk_id_product_in_prod_vend` (`fk_id_product_main`),
  KEY `fk_id_vendor_in_prod_vend` (`fk_id_vendor`),
  KEY `idx_id_ent_in_prod_vendor` (`fk_id_ent`),
  KEY `idx_sku_in_vendor_product` (`SKU_VENDOR`),
  KEY `idx_id_account_in_vendor_product` (`fk_id_account_added_by`),
  KEY `idx_id_acc_in_prod_vendor` (`fk_id_account_updated_by`),
  KEY `idx_id_type_prod_in_vendor_prod` (`fk_id_product_type`),
  KEY `fk_id_packing_in_prod_vend` (`fk_id_packing`),
  KEY `fk_id_trend_in_prod_venodr` (`fk_id_product_trend`),
  KEY `fk_id_pkg_svc_in_prod_vendor` (`fk_id_car_pkg_svc`),
  KEY `idx_fk_id_vendor` (`fk_id_vendor`),
  KEY `idx_sku_vendor_in_tpv` (`SKU_VENDOR`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_vendors_dec_2025`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_vendors_dec_2025` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) NOT NULL,
  `product_name_vendor` varchar(255) NOT NULL,
  `ean` varchar(20) DEFAULT NULL,
  `fk_id_product_type` int(11) NOT NULL,
  `fk_id_product_trend` int(11) DEFAULT NULL,
  `fk_id_product_vendor_self` int(11) DEFAULT NULL,
  `variant` varchar(70) DEFAULT NULL,
  `d_created` date DEFAULT NULL,
  `fk_id_country_make` int(11) DEFAULT NULL,
  `weight` varchar(50) DEFAULT NULL,
  `dimensions` varchar(15) DEFAULT NULL,
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `fk_id_packing` int(11) DEFAULT NULL,
  `url_ebay` varchar(500) DEFAULT NULL,
  `ebay_fee` decimal(10,2) DEFAULT NULL,
  `sale_price_with_vat` decimal(10,2) DEFAULT NULL,
  `sale_price` decimal(10,2) NOT NULL,
  `list_price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL,
  `f_out_of_stock` tinyint(4) NOT NULL DEFAULT 0,
  `min_level` int(11) NOT NULL,
  `f_alert_min_level` tinyint(1) NOT NULL,
  `href_product` varchar(255) DEFAULT NULL,
  `SKU_VENDOR` varchar(50) NOT NULL,
  `opc` varchar(50) DEFAULT NULL,
  `upc` varchar(50) DEFAULT NULL,
  `fk_id_account_added_by` int(11) DEFAULT NULL,
  `fk_id_account_updated_by` int(11) DEFAULT NULL,
  `f_active` tinyint(1) NOT NULL,
  `carrier` varchar(500) NOT NULL,
  `f_tag1` tinyint(4) NOT NULL DEFAULT 0,
  `f_tag2` tinyint(4) NOT NULL DEFAULT 0,
  `f_tag3` tinyint(4) NOT NULL DEFAULT 0,
  `d_product_expiry_date` date DEFAULT '0000-00-00',
  `fk_id_role` int(11) NOT NULL DEFAULT 0,
  `fk_id_role_account` int(11) NOT NULL DEFAULT 0,
  `fk_id_vendor_inventory` int(11) NOT NULL DEFAULT 0,
  `f_parent` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=single,2=variant parent, 3=variant child',
  `f_id_parent` int(11) NOT NULL DEFAULT 0,
  `f_color` varchar(50) DEFAULT NULL,
  `f_size` varchar(20) DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `f_group_name` varchar(255) DEFAULT NULL,
  `product_img` varchar(100) DEFAULT NULL,
  `hs_code` varchar(255) NOT NULL,
  `product_description` mediumtext DEFAULT NULL,
  `fk_id_role2` int(11) NOT NULL DEFAULT 0,
  `fk_id_role_account2` int(11) NOT NULL DEFAULT 0,
  `fk_id_role3` int(11) NOT NULL DEFAULT 0,
  `fk_id_role_account3` int(11) NOT NULL DEFAULT 0,
  `fk_id_branch` tinyint(4) NOT NULL DEFAULT 0,
  `raw_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`SKU_VENDOR`),
  UNIQUE KEY `fk_id_product` (`fk_id_product_main`,`fk_id_vendor`),
  KEY `fk_id_product_in_prod_vend` (`fk_id_product_main`),
  KEY `fk_id_vendor_in_prod_vend` (`fk_id_vendor`),
  KEY `idx_id_ent_in_prod_vendor` (`fk_id_ent`),
  KEY `idx_sku_in_vendor_product` (`SKU_VENDOR`),
  KEY `idx_id_account_in_vendor_product` (`fk_id_account_added_by`),
  KEY `idx_id_acc_in_prod_vendor` (`fk_id_account_updated_by`),
  KEY `idx_id_type_prod_in_vendor_prod` (`fk_id_product_type`),
  KEY `fk_id_packing_in_prod_vend` (`fk_id_packing`),
  KEY `fk_id_trend_in_prod_venodr` (`fk_id_product_trend`),
  KEY `fk_id_pkg_svc_in_prod_vendor` (`fk_id_car_pkg_svc`),
  KEY `idx_fk_id_vendor` (`fk_id_vendor`),
  KEY `idx_sku_vendor_in_tpv` (`SKU_VENDOR`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_vendors_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_vendors_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_product_vendors` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `thumb_url` varchar(500) DEFAULT NULL,
  `f_main` tinyint(4) NOT NULL DEFAULT 0,
  `sort_no` smallint(6) NOT NULL DEFAULT 0,
  `alt_text` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fk_id_product_vendors` (`fk_id_product_vendors`),
  KEY `idx_fk_id_marketplace` (`fk_id_marketplace`),
  KEY `idx_img_product` (`fk_id_product_vendors`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_vendors_temp`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_vendors_temp` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `f_family` tinyint(1) DEFAULT 0,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `fk_id_vendor_inventory` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `sku_number` varchar(255) DEFAULT NULL,
  `product_info` text DEFAULT NULL,
  `fk_id_product_type` int(11) DEFAULT 30,
  `sale_price` decimal(10,2) DEFAULT 0.00,
  `qty` int(11) DEFAULT 1,
  `fk_id_car_pkg_svc` int(11) DEFAULT 0,
  `fk_id_packing` int(11) NOT NULL DEFAULT 0,
  `weight` varchar(20) NOT NULL,
  `dimensions` varchar(20) NOT NULL,
  `f_parent` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=single,2=variant parent, 3=variant child',
  `f_id_parent` int(11) NOT NULL DEFAULT 0,
  `fk_id_product_vendor_self` varchar(10) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_vendors_variation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_vendors_variation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_product_vendors` int(11) NOT NULL,
  `sku_vendor` varchar(200) NOT NULL,
  `sale_price` decimal(10,2) NOT NULL,
  `landing_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_visibility`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_visibility` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_product` int(11) NOT NULL COMMENT 'FK -> t_product_vendors.id',
  `fk_id_marketplace` int(11) NOT NULL COMMENT 'FK -> cat_marketplaces.id',
  `visibility_mode` enum('pool','private') NOT NULL DEFAULT 'pool',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_marketplace` (`fk_id_product`,`fk_id_marketplace`),
  KEY `idx_marketplace_mode` (`fk_id_marketplace`,`visibility_mode`),
  KEY `idx_vis_product_mode` (`fk_id_product`,`visibility_mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_product_visibility_shops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_product_visibility_shops` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_product` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL,
  `fk_id_branch` int(11) NOT NULL COMMENT 'FK -> t_branches.id',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_branch` (`fk_id_product`,`fk_id_branch`),
  KEY `idx_product_marketplace` (`fk_id_product`,`fk_id_marketplace`),
  KEY `idx_branch` (`fk_id_branch`),
  KEY `idx_vshops_product` (`fk_id_product`,`fk_id_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_products_branch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_products_branch` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_branch` int(11) NOT NULL,
  `sku_ebay` varchar(25) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `product_name_local` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `fk_id_product_type` int(11) NOT NULL,
  `sale_price_ebay` decimal(10,2) NOT NULL,
  `url_ebay_product` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_price` decimal(10,2) DEFAULT NULL,
  `sku_vendor` varchar(25) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `f_active` tinyint(1) NOT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL,
  `url_vendor_product` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `asin_amazon` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `ref_vendor_product` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_branch` (`fk_id_branch`,`product_name_local`),
  UNIQUE KEY `idx_sku_ebay_in_prod_branch` (`sku_ebay`) USING BTREE,
  KEY `fk_id_product_in_prod_branch` (`fk_id_product_main`),
  KEY `idx_branch_in_prod_branch` (`fk_id_branch`),
  KEY `idx_id_acc_in_prod_branch` (`fk_id_vendor`),
  KEY `idx_id_prod_type_in_prod_shop` (`fk_id_product_type`),
  KEY `idx_id_carrier_in_product_shop` (`fk_id_carrier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_products_listed`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_products_listed` (
  `fk_id_branch` int(11) NOT NULL,
  `sku_used` varchar(200) NOT NULL,
  `product_detail` varchar(1000) NOT NULL,
  KEY `idx_fk_id_branch_in_tpl` (`fk_id_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_products_main`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_products_main` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `product_name` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `description` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `f_active` tinyint(1) NOT NULL,
  `fk_id_product_type` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`product_name`),
  KEY `fk_id_type_in_products` (`fk_id_product_type`),
  KEY `idx_ent_in_products` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_role_rights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_role_rights` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_role` int(11) NOT NULL,
  `fk_id_right` int(11) NOT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_role_in_role_rights` (`fk_id_role`),
  KEY `idx_id_right_in_role_rights` (`fk_id_right`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_sale`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_sale` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_main` int(11) NOT NULL,
  `d_sale` date NOT NULL,
  `order_id` varchar(40) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `fk_id_product` int(11) DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `item_title` varchar(1000) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `customer_name` varchar(250) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `cust_address` varchar(800) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `no_of_items` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `item_note` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `item_note_orig` varchar(500) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `order_id_buy_in_sale` varchar(50) DEFAULT NULL,
  `reason_by_va` varchar(250) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_id_main_in_sale` (`fk_id_main`),
  KEY `fk_id_vendor_in_sale` (`fk_id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_sale_buy_main`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_sale_buy_main` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mon` int(11) NOT NULL,
  `yr` int(11) NOT NULL,
  `d_yr_mon` date NOT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `f_sale_loaded` int(11) NOT NULL DEFAULT 0,
  `d_sale_summary` datetime DEFAULT NULL,
  `f_summary_generated` tinyint(4) NOT NULL DEFAULT 0,
  `tot_sale` decimal(10,2) DEFAULT NULL,
  `sale_fee` decimal(10,2) DEFAULT NULL,
  `net_sale` decimal(10,2) DEFAULT NULL,
  `net_buy` decimal(10,2) DEFAULT NULL,
  `tot_profit` decimal(10,2) DEFAULT NULL,
  `tot_expenses` decimal(10,2) NOT NULL,
  `expenses_descr` varchar(200) DEFAULT NULL,
  `net_profit` decimal(10,2) DEFAULT NULL,
  `seller_per` decimal(10,2) DEFAULT NULL,
  `seller_profit_in_pund` decimal(10,2) DEFAULT NULL,
  `exchange_rate` decimal(10,2) DEFAULT NULL,
  `seller_profit_in_pkr` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mon` (`mon`,`yr`,`fk_id_branch`),
  KEY `fk_branch_in_t_sale_buy_main` (`fk_id_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_sale_change_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_sale_change_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_sale` bigint(20) NOT NULL,
  `save_key` varchar(40) NOT NULL DEFAULT '',
  `action` varchar(40) NOT NULL COMMENT 'Edit Carrier/Packing | Edit Sale | Refund Sale',
  `field_name` varchar(50) NOT NULL COMMENT 'column key, e.g. carrier, packing, qty',
  `field_label` varchar(100) NOT NULL COMMENT 'shown in Log History, e.g. Carrier',
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `fk_id_user` int(11) DEFAULT NULL,
  `changed_by` varchar(150) NOT NULL DEFAULT '',
  `changed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_scl_sale` (`fk_id_sale`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_sale_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_sale_charges` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_sale` bigint(20) DEFAULT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `order_no` varchar(100) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `fk_id_product` int(11) DEFAULT NULL,
  `kind` varchar(30) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  `amount_gbp` decimal(12,2) DEFAULT NULL,
  `d_charge` date NOT NULL,
  `f_estimated` tinyint(4) NOT NULL DEFAULT 0,
  `fk_id_batch` int(11) DEFAULT NULL,
  `row_no` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `refund_id` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_charge_refund` (`order_no`,`sku`,`refund_id`),
  KEY `ix_sale` (`fk_id_sale`),
  KEY `ix_order` (`fk_id_branch`,`order_no`),
  KEY `ix_kind` (`kind`,`d_charge`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_sale_import_errors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_sale_import_errors` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_import` int(11) NOT NULL,
  `row_no` int(11) NOT NULL COMMENT 'Line number in the CSV file',
  `order_no` varchar(100) NOT NULL DEFAULT '',
  `sku` varchar(100) NOT NULL DEFAULT '',
  `reason` varchar(255) NOT NULL,
  `raw_csv` longtext DEFAULT NULL,
  `f_resolved` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `f_unmatched` tinyint(4) NOT NULL DEFAULT 0 COMMENT '1 = not in inventory and not matched in drp_items',
  PRIMARY KEY (`id`),
  KEY `idx_sie_import` (`fk_id_import`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_sale_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_sale_imports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL,
  `import_nature` varchar(20) NOT NULL DEFAULT 'Normal',
  `file_name` varchar(255) NOT NULL DEFAULT '',
  `period_month` tinyint(4) NOT NULL,
  `period_year` smallint(6) NOT NULL,
  `marketplace_fee` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Fixed amount, subtracted once from this batch profit',
  `fk_id_va_account` int(11) DEFAULT NULL COMMENT 'Shop seller at import time',
  `va_share_percentage` decimal(6,2) NOT NULL DEFAULT 0.00 COMMENT 'Snapshot from t_account_job_history',
  `total_rows` int(11) NOT NULL DEFAULT 0 COMMENT 'Data rows read from the CSV',
  `saved_rows` int(11) NOT NULL DEFAULT 0,
  `error_rows` int(11) NOT NULL DEFAULT 0,
  `skipped_rows` int(11) NOT NULL DEFAULT 0 COMMENT 'Blank / summary / footer lines',
  `out_of_period_rows` int(11) NOT NULL DEFAULT 0,
  `status` enum('Pending','Partially Confirmed','Confirmed') NOT NULL DEFAULT 'Pending',
  `imported_by` int(11) DEFAULT NULL,
  `imported_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `confirmed_at` datetime DEFAULT NULL,
  `confirmed_by` int(11) DEFAULT NULL,
  `confirmed_by_name` varchar(150) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sale_import_shop_month` (`fk_id_branch`,`period_year`,`period_month`),
  KEY `idx_si_ent` (`fk_id_ent`),
  KEY `idx_si_marketplace` (`fk_id_marketplace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_sale_order_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_sale_order_reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_branch` int(11) NOT NULL,
  `fk_id_rem_type` int(11) NOT NULL,
  `sku_ebay` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ebay_order_no` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `fk_id_t_sale_orders` int(11) NOT NULL,
  `d_entered` datetime NOT NULL,
  `fk_id_t_users` int(11) NOT NULL,
  `e_status` enum('PENDING','RESOLVED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `va_remarks` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_remarks` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fk_id_customer` int(11) DEFAULT NULL,
  `case_channel` varchar(20) DEFAULT NULL,
  `priority` varchar(8) DEFAULT NULL,
  `d_reply_by` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_branch_in_sale_reminders` (`fk_id_branch`),
  KEY `fk_id_rem_type_in_sale_reminders` (`fk_id_rem_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_sale_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_sale_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_branch` int(11) NOT NULL,
  `ebay_order_no` varchar(30) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `d_ebay_order` datetime NOT NULL,
  `cust_name` varchar(250) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `cust_phone` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `cust_address` varchar(1500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `fk_id_product_branch` int(11) NOT NULL,
  `f_local` int(11) NOT NULL DEFAULT 1,
  `qty_ordered` int(11) NOT NULL,
  `sale_rate` decimal(10,2) NOT NULL,
  `c_tot_amount` decimal(10,2) NOT NULL,
  `c_tot_discounts` decimal(10,2) NOT NULL,
  `c_tot_add` decimal(10,2) NOT NULL,
  `c_grand_total` decimal(11,2) NOT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_ref_no` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `item_note` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `item_note_orig` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `sku_ebay` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `reason_by_va` varchar(250) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_carrier_pkg` smallint(6) NOT NULL DEFAULT 0,
  `fk_id_package` smallint(6) NOT NULL DEFAULT 0,
  `price_single_raw` decimal(10,2) NOT NULL DEFAULT 0.00,
  `product_profit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `product_loss` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `fk_id_branch_in_sale` (`fk_id_branch`),
  KEY `ebay_order_no` (`ebay_order_no`),
  KEY `fk_id_product_branch_in_sale` (`fk_id_product_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_sale_orders_1`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_sale_orders_1` (
  `id` int(11) NOT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `ebay_order_no` varchar(30) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `d_ebay_order` datetime NOT NULL,
  `cust_name` varchar(250) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `cust_phone` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `cust_address` varchar(1500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `fk_id_product_branch` int(11) NOT NULL,
  `f_local` int(11) NOT NULL DEFAULT 1,
  `qty_ordered` int(11) NOT NULL,
  `sale_rate` decimal(10,2) NOT NULL,
  `c_tot_amount` decimal(10,2) NOT NULL,
  `c_tot_discounts` decimal(10,2) NOT NULL,
  `c_tot_add` decimal(10,2) NOT NULL,
  `c_grand_total` decimal(11,2) NOT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_ref_no` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `item_note` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `item_note_orig` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `sku_ebay` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `reason_by_va` varchar(250) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_id_branch_in_sale` (`fk_id_branch`),
  KEY `ebay_order_no` (`ebay_order_no`),
  KEY `fk_id_product_branch_in_sale` (`fk_id_product_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_sale_orders_new`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_sale_orders_new` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_vendor_inventory` int(11) DEFAULT NULL,
  `fk_id_marketplace` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `ebay_order_no` varchar(30) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `d_ebay_order` datetime DEFAULT NULL,
  `cust_name` varchar(250) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `cust_phone` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `cust_address` varchar(1500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `product_info` text DEFAULT NULL,
  `fk_id_product_branch` int(11) DEFAULT NULL,
  `f_local` int(11) DEFAULT 1,
  `qty_ordered` int(11) DEFAULT NULL,
  `sale_rate` decimal(10,2) DEFAULT NULL,
  `c_grand_total` decimal(11,2) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_ref_no` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `pur_amount` decimal(10,2) DEFAULT 0.00,
  `item_note` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `item_note_orig` varchar(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `sku_ebay` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `reason_by_va` varchar(250) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fk_id_carrier_pkg` int(11) DEFAULT 0,
  `fk_id_package` int(11) DEFAULT 0,
  `price_single_raw` decimal(10,2) DEFAULT 0.00,
  `product_profit` decimal(10,2) DEFAULT 0.00,
  `product_loss` decimal(10,2) DEFAULT 0.00,
  `import_nature` enum('Normal_Sale','Manual') DEFAULT 'Normal_Sale',
  `marketplace_fee` float(10,0) NOT NULL DEFAULT 0,
  `sale_order_status` enum('Success','Refunded','Loss_Sale') NOT NULL DEFAULT 'Success',
  `refunded_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `refunded_by` tinyint(10) NOT NULL,
  `refunded_reason` text NOT NULL,
  `loss_nature` enum('Product','Carrier','Packing','Carrier_Packing','Product_Carrier','Product_Packing','Product_Carrier_Packing') NOT NULL DEFAULT 'Product',
  PRIMARY KEY (`id`),
  KEY `fk_id_branch_in_sale` (`fk_id_branch`),
  KEY `ebay_order_no` (`ebay_order_no`),
  KEY `fk_id_product_branch_in_sale` (`fk_id_product_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_sale_refund_proposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_sale_refund_proposals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_sale` bigint(20) NOT NULL,
  `fk_id_batch` int(11) DEFAULT NULL,
  `refund_id` varchar(80) NOT NULL,
  `refund_kind` varchar(20) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'OPEN',
  `reject_reason` text DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refund` (`fk_id_sale`,`refund_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Refund proposals from the money report (IC-12)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_sale_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_sale_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_sale` bigint(20) NOT NULL,
  `reason_code` varchar(40) DEFAULT NULL,
  `condition_code` varchar(40) DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `d_received` date DEFAULT NULL,
  `refund_amount` decimal(14,2) DEFAULT NULL,
  `refund_kind` varchar(20) DEFAULT NULL,
  `restock_to` varchar(20) DEFAULT NULL,
  `label_cost` decimal(14,2) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `fk_id_return_intake` int(11) DEFAULT NULL,
  `grade` varchar(24) DEFAULT NULL,
  `fk_id_restock_location` int(11) DEFAULT NULL,
  `f_before_confirm` tinyint(4) NOT NULL DEFAULT 0,
  `fk_id_parcel` int(11) DEFAULT NULL,
  `fk_id_grn_claim` int(11) DEFAULT NULL,
  `refund_review` varchar(12) DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `client_op_id` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ret_sale` (`fk_id_sale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Returned goods';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_sales` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_import` int(11) NOT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL,
  `fk_product_id` int(11) NOT NULL COMMENT 't_product_vendors.id matched by SKU',
  `fk_id_vendor` int(11) DEFAULT NULL,
  `order_no` varchar(100) NOT NULL,
  `d_order` date NOT NULL,
  `cust_name` text NOT NULL,
  `cust_contacts` text NOT NULL,
  `sku` varchar(100) NOT NULL,
  `product_info` text NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `sale_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `raw_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Unit raw price on the order date',
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `carrier_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fk_id_packing` int(11) DEFAULT NULL,
  `packing_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `buy_rate` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'raw x qty',
  `buy_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'qty=1: raw x qty | qty>1: raw x qty + carrier + packing',
  `profit` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'total - (raw x qty + carrier + packing); negative = loss',
  `d_buy` date DEFAULT NULL,
  `product_url` varchar(500) NOT NULL DEFAULT '',
  `cost_source` varchar(20) NOT NULL DEFAULT 'period' COMMENT 'period | period_old | legacy_period | current | product',
  `fk_id_price_period` bigint(20) DEFAULT NULL COMMENT 't_product_inventory_change_log.id used',
  `f_out_of_period` tinyint(4) NOT NULL DEFAULT 0 COMMENT '1 = order date outside the import month',
  `status` enum('Pending','Confirmed','Cancelled','Refunded') NOT NULL DEFAULT 'Pending',
  `f_stock_deducted` tinyint(4) NOT NULL DEFAULT 0,
  `d_confirmed` datetime DEFAULT NULL,
  `confirmed_by` int(11) DEFAULT NULL,
  `f_edited` tinyint(4) NOT NULL DEFAULT 0,
  `raw_csv` longtext DEFAULT NULL COMMENT 'Original CSV row as JSON',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `refund_loss_nature` varchar(40) NOT NULL DEFAULT '' COMMENT 'Product | Carrier | Packing | Carrier_Packing | Product_Carrier | Product_Packing | Product_Carrier_Packing',
  `refund_loss_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_reason` text DEFAULT NULL,
  `refunded_at` datetime DEFAULT NULL,
  `refunded_by` int(11) DEFAULT NULL,
  `refunded_by_name` varchar(150) NOT NULL DEFAULT '',
  `visibility_mode` varchar(10) NOT NULL DEFAULT '' COMMENT 'pool | private | empty = not set',
  `fk_id_company` int(11) DEFAULT NULL COMMENT 'cat_company_house.id (shop company)',
  `sale_source` enum('active','ds','unmatched') NOT NULL DEFAULT 'active' COMMENT 'active = SKU in t_product_vendors | ds = matched to drp_items | unmatched = neither (yet)',
  `fk_drp_item` int(11) DEFAULT NULL COMMENT 'drp_items.id the line was matched to (ds lines only)',
  `ds_match_count` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'How many of the 5 columns matched (name, address, city, postcode, phone)',
  `ds_match_json` text DEFAULT NULL COMMENT 'Per column: drp_items value, CSV value, matched yes/no (for the badge popup)',
  `ds_note` varchar(255) NOT NULL DEFAULT '' COMMENT 'unmatched: why it was not matched',
  `fk_id_listing` int(11) DEFAULT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  `total_amount_gbp` decimal(12,2) DEFAULT NULL,
  `cost_gbp` decimal(12,2) DEFAULT NULL,
  `net_after_charges` decimal(12,2) DEFAULT NULL,
  `fk_id_batch` int(11) DEFAULT NULL,
  `batch_row_no` int(11) DEFAULT NULL,
  `fk_id_audit_run` int(11) DEFAULT NULL,
  `f_locked` tinyint(4) NOT NULL DEFAULT 0,
  `legacy_table` varchar(40) DEFAULT NULL,
  `legacy_id` bigint(20) DEFAULT NULL,
  `line_status` varchar(12) DEFAULT NULL,
  `fulfilment_channel` varchar(20) DEFAULT NULL,
  `fk_id_sell_location` int(11) DEFAULT NULL,
  `tracking_no` varchar(60) DEFAULT NULL,
  `carrier_service_text` varchar(150) DEFAULT NULL,
  `label_status` varchar(12) DEFAULT NULL,
  `label_file` varchar(255) DEFAULT NULL,
  `ship_to_name` varchar(150) DEFAULT NULL,
  `ship_to_phone` varchar(40) DEFAULT NULL,
  `ship_to_address` varchar(300) DEFAULT NULL,
  `ship_to_city` varchar(100) DEFAULT NULL,
  `ship_to_postcode` varchar(20) DEFAULT NULL,
  `ship_to_country_code` char(2) DEFAULT NULL,
  `parcel_weight_g` int(11) DEFAULT NULL,
  `parcel_l_cm` decimal(8,2) DEFAULT NULL,
  `parcel_w_cm` decimal(8,2) DEFAULT NULL,
  `parcel_h_cm` decimal(8,2) DEFAULT NULL,
  `fk_id_refund_proposal` int(11) DEFAULT NULL,
  `dispatch_by` datetime DEFAULT NULL,
  `wh_status` varchar(12) DEFAULT NULL,
  `qty_shipped` int(11) NOT NULL DEFAULT 0,
  `qty_returned_unconfirmed` int(11) NOT NULL DEFAULT 0,
  `f_ship_backfill` tinyint(4) NOT NULL DEFAULT 0,
  `fk_id_customer` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sale_line` (`fk_id_branch`,`order_no`,`sku`),
  UNIQUE KEY `uq_sales_drp_item` (`fk_drp_item`),
  KEY `idx_sales_import` (`fk_id_import`),
  KEY `idx_sales_branch_date` (`fk_id_branch`,`d_order`),
  KEY `idx_sales_mp_date` (`fk_id_marketplace`,`d_order`),
  KEY `idx_sales_product` (`fk_product_id`),
  KEY `idx_sales_status` (`status`),
  KEY `idx_sales_ent` (`fk_id_ent`),
  KEY `idx_sales_visibility` (`visibility_mode`),
  KEY `idx_sales_company` (`fk_id_company`),
  KEY `idx_sales_carrier` (`fk_id_car_pkg_svc`),
  KEY `idx_sales_source` (`sale_source`),
  KEY `ix_order` (`fk_id_branch`,`order_no`),
  KEY `ix_period` (`fk_id_branch`,`d_order`),
  KEY `ix_sales_order_sku` (`fk_id_branch`,`order_no`,`sku`),
  KEY `ix_sales_dispatch` (`wh_status`,`dispatch_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_shop_ccw_seller_resolution`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_shop_ccw_seller_resolution` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_shop` int(11) NOT NULL,
  `no_of_cases` decimal(10,2) NOT NULL,
  `fk_id_account` int(11) NOT NULL,
  `f_date` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_shop_feedbacks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_shop_feedbacks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_id_shop` int(11) NOT NULL,
  `pf_week_1` int(11) NOT NULL DEFAULT 0,
  `pf_week_2` int(11) NOT NULL DEFAULT 0,
  `pf_week_3` int(11) NOT NULL DEFAULT 0,
  `pf_week_4` int(11) NOT NULL DEFAULT 0,
  `nf_week_1` text NOT NULL,
  `nf_week_2` text NOT NULL,
  `nf_week_3` int(11) NOT NULL DEFAULT 0,
  `nf_week_4` int(11) NOT NULL DEFAULT 0,
  `tdr_week_1` float(10,2) NOT NULL DEFAULT 0.00,
  `tdr_week_2` float(10,2) NOT NULL DEFAULT 0.00,
  `ldr_week_1` float(10,2) NOT NULL DEFAULT 0.00,
  `ldr_week_2` float(10,2) NOT NULL DEFAULT 0.00,
  `ccwsr_week_1` int(11) NOT NULL DEFAULT 0,
  `ccwsr_week_2` int(11) NOT NULL DEFAULT 0,
  `seller_level` int(11) NOT NULL DEFAULT 0,
  `f_date` date NOT NULL,
  `pf_week_1_date` date NOT NULL DEFAULT '0000-00-00',
  `pf_week_2_date` date NOT NULL DEFAULT '0000-00-00',
  `pf_week_3_date` date NOT NULL DEFAULT '0000-00-00',
  `pf_week_4_date` date NOT NULL DEFAULT '0000-00-00',
  `nf_week_1_date` date NOT NULL DEFAULT '0000-00-00',
  `nf_week_2_date` date NOT NULL DEFAULT '0000-00-00',
  `nf_week_3_date` date NOT NULL DEFAULT '0000-00-00',
  `nf_week_4_date` date NOT NULL DEFAULT '0000-00-00',
  `tdr_week_1_date` date NOT NULL DEFAULT '0000-00-00',
  `tdr_week_2_date` date NOT NULL DEFAULT '0000-00-00',
  `ldr_week_1_date` date NOT NULL DEFAULT '0000-00-00',
  `ldr_week_2_date` date NOT NULL DEFAULT '0000-00-00',
  `ccwsr_week_1_date` date NOT NULL DEFAULT '0000-00-00',
  `ccwsr_week_2_date` date NOT NULL DEFAULT '0000-00-00',
  `seller_level_date` date NOT NULL DEFAULT '0000-00-00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_shop_late_delivery_rate`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_shop_late_delivery_rate` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_shop` int(11) NOT NULL,
  `late_delivery_rate` decimal(10,2) NOT NULL,
  `fk_id_account` int(11) NOT NULL,
  `f_date` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_shop_negative_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_shop_negative_feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_shop` int(11) NOT NULL,
  `shop_feedback` decimal(10,2) NOT NULL,
  `fk_id_account` int(11) NOT NULL,
  `f_date` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_shop_positive_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_shop_positive_feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_shop` int(11) NOT NULL,
  `shop_feedback` decimal(10,2) NOT NULL,
  `fk_id_account` int(11) NOT NULL,
  `f_date` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_shop_seller_level`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_shop_seller_level` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_shop` int(11) NOT NULL,
  `seller_level` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=Top Rate, 2=Above Standard, 3=Below Standard',
  `fk_id_account` int(11) NOT NULL,
  `f_date` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_shop_transaction_defect_rate`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_shop_transaction_defect_rate` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_shop` int(11) NOT NULL,
  `trans_defect_rate` decimal(10,2) NOT NULL,
  `fk_id_account` int(11) NOT NULL,
  `f_date` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `alloc_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_po` int(11) DEFAULT NULL,
  `fk_id_cycle_product` int(11) DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `dest_entity` varchar(255) DEFAULT NULL,
  `fk_id_warehouse` int(11) DEFAULT NULL,
  `fk_id_marketplace` int(11) DEFAULT NULL,
  `seller_account` varchar(255) DEFAULT NULL,
  `allocated_qty` decimal(15,4) DEFAULT 0.0000,
  `confirmed_qty` decimal(15,4) DEFAULT 0.0000,
  `dispatched_qty` decimal(15,4) DEFAULT 0.0000,
  `received_qty` decimal(15,4) DEFAULT 0.0000,
  `accepted_qty` decimal(15,4) DEFAULT 0.0000,
  `damaged_qty` decimal(15,4) DEFAULT 0.0000,
  `missing_qty` decimal(15,4) DEFAULT 0.0000,
  `status` varchar(255) DEFAULT NULL,
  `planned_dispatch` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cycle` (`fk_id_cycle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_cartons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_cartons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_po` int(11) DEFAULT NULL,
  `fk_id_cycle_product` int(11) DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `config_name` varchar(255) DEFAULT NULL,
  `unit_l` decimal(15,4) DEFAULT 0.0000,
  `unit_w` decimal(15,4) DEFAULT 0.0000,
  `unit_h` decimal(15,4) DEFAULT 0.0000,
  `unit_cbm` decimal(15,4) DEFAULT 0.0000,
  `volumetric_weight` decimal(15,4) DEFAULT 0.0000,
  `unit_net_weight` decimal(15,4) DEFAULT 0.0000,
  `unit_gross_weight` decimal(15,4) DEFAULT 0.0000,
  `carton_tare_weight` decimal(15,4) DEFAULT 0.0000,
  `units_per_carton` decimal(15,4) DEFAULT 0.0000,
  `total_planned_units` decimal(15,4) DEFAULT 0.0000,
  `full_cartons` decimal(15,4) DEFAULT 0.0000,
  `loose_units` decimal(15,4) DEFAULT 0.0000,
  `carton_qty` decimal(15,4) DEFAULT 0.0000,
  `carton_l` decimal(15,4) DEFAULT 0.0000,
  `carton_w` decimal(15,4) DEFAULT 0.0000,
  `carton_h` decimal(15,4) DEFAULT 0.0000,
  `carton_net_weight` decimal(15,4) DEFAULT 0.0000,
  `carton_gross_weight` decimal(15,4) DEFAULT 0.0000,
  `actual_carton_gross_weight` decimal(15,4) DEFAULT 0.0000,
  `hs_code` varchar(255) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `total_units` decimal(15,4) DEFAULT 0.0000,
  `total_net_weight` decimal(15,4) DEFAULT 0.0000,
  `total_gross_weight` decimal(15,4) DEFAULT 0.0000,
  `carton_cbm` decimal(15,4) DEFAULT 0.0000,
  `total_cbm` decimal(15,4) DEFAULT 0.0000,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_customs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_customs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `customs_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_shipment` int(11) DEFAULT NULL,
  `importer_of_record` varchar(255) DEFAULT NULL,
  `declaration_number` varchar(255) DEFAULT NULL,
  `hs_code` varchar(255) DEFAULT NULL,
  `customs_qty` decimal(15,4) DEFAULT 0.0000,
  `customs_value` decimal(15,4) DEFAULT 0.0000,
  `fk_id_currency` int(11) DEFAULT NULL,
  `duty_rate` decimal(15,4) DEFAULT 0.0000,
  `duty_amount` decimal(15,4) DEFAULT 0.0000,
  `import_vat` decimal(15,4) DEFAULT 0.0000,
  `status` varchar(255) DEFAULT NULL,
  `clearance_date` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `exchange_rate` decimal(18,6) DEFAULT NULL,
  `base_amount` decimal(14,2) DEFAULT NULL,
  `fk_id_product` int(11) DEFAULT NULL,
  `fk_id_broker` int(11) DEFAULT NULL,
  `pay_status` varchar(12) DEFAULT NULL,
  `paid_date` date DEFAULT NULL,
  `fk_id_bank_line` int(11) DEFAULT NULL,
  `paid_base_amount` decimal(14,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_cycle_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_cycle_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_cycle` int(11) NOT NULL,
  `fk_id_brand` int(11) DEFAULT NULL,
  `fk_id_supplier` int(11) DEFAULT NULL,
  `fk_id_product` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `variation` varchar(255) DEFAULT NULL,
  `target_qty` decimal(15,4) DEFAULT 0.0000,
  `target_unit_cost` decimal(15,4) DEFAULT 0.0000,
  `product_spec` text DEFAULT NULL,
  `row_status` varchar(100) DEFAULT 'Draft',
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cycle` (`fk_id_cycle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_cycles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_cycles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `cycle_code` varchar(255) DEFAULT NULL,
  `sourcing_date` date DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `brand` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `variation` varchar(255) DEFAULT NULL,
  `target_qty` decimal(15,4) DEFAULT 0.0000,
  `target_unit_cost` decimal(15,4) DEFAULT 0.0000,
  `required_delivery_date` date DEFAULT NULL,
  `product_spec` text DEFAULT NULL,
  `priority` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `cycle_type` varchar(20) NOT NULL DEFAULT 'FULL',
  `skipped_steps` varchar(255) NOT NULL DEFAULT '',
  `order_ref` varchar(150) DEFAULT NULL,
  `approval_status` varchar(20) NOT NULL DEFAULT 'APPROVED',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `order_ref_note` varchar(255) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `fk_id_main_branch` int(11) DEFAULT NULL,
  `drive_folder_url` varchar(500) DEFAULT NULL,
  `fk_id_research` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `doc_name` varchar(255) DEFAULT NULL,
  `doc_type` varchar(255) DEFAULT NULL,
  `doc_category` varchar(255) DEFAULT NULL,
  `doc_status` varchar(255) DEFAULT NULL,
  `drive_url` varchar(255) DEFAULT NULL,
  `fk_id_product` int(11) DEFAULT NULL,
  `fk_id_cycle_product` int(11) DEFAULT NULL,
  `fk_id_po` int(11) DEFAULT NULL,
  `fk_id_payment` int(11) DEFAULT NULL,
  `fk_id_shipment` int(11) DEFAULT NULL,
  `fk_id_qc` int(11) DEFAULT NULL,
  `fk_id_customs` int(11) DEFAULT NULL,
  `fk_id_grn` int(11) DEFAULT NULL,
  `fk_id_rfq` int(11) DEFAULT NULL,
  `fk_id_sample` int(11) DEFAULT NULL,
  `fk_id_production` int(11) DEFAULT NULL,
  `fk_id_freight` int(11) DEFAULT NULL,
  `fk_id_allocation` int(11) DEFAULT NULL,
  `fk_id_investment` int(11) DEFAULT NULL,
  `fk_id_po_line` int(11) DEFAULT NULL,
  `fk_id_term` int(11) DEFAULT NULL,
  `fk_id_inventory` int(11) DEFAULT NULL,
  `uploaded_by` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_forwarders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_forwarders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `forwarder_code` varchar(30) DEFAULT NULL,
  `forwarder_name` varchar(200) NOT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `wechat` varchar(100) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `services` varchar(300) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Forwarder master (old code table; created here when absent)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_freight`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_freight` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `freight_code` varchar(255) DEFAULT NULL,
  `fk_id_shipment` int(11) DEFAULT NULL,
  `fk_id_forwarder` int(11) DEFAULT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  `exchange_rate` decimal(15,2) DEFAULT 0.00,
  `origin_charges` decimal(15,2) DEFAULT 0.00,
  `export_clearance` decimal(15,2) DEFAULT 0.00,
  `main_freight` decimal(15,2) DEFAULT 0.00,
  `destination_charges` decimal(15,2) DEFAULT 0.00,
  `customs_clearance` decimal(15,2) DEFAULT 0.00,
  `last_mile` decimal(15,2) DEFAULT 0.00,
  `insurance` decimal(15,2) DEFAULT 0.00,
  `other_cost` decimal(15,2) DEFAULT 0.00,
  `total_cost` decimal(15,2) DEFAULT 0.00,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_freight_costs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_freight_costs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `freight_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_shipment` int(11) DEFAULT NULL,
  `fk_id_forwarder` int(11) DEFAULT NULL,
  `cost_type` varchar(255) DEFAULT NULL,
  `amount` decimal(15,4) DEFAULT 0.0000,
  `fk_id_currency` int(11) DEFAULT NULL,
  `exchange_rate` decimal(15,4) DEFAULT 0.0000,
  `base_amount` decimal(15,4) DEFAULT 0.0000,
  `cost_date` date DEFAULT NULL,
  `invoice_ref` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `source_po_line_id` int(11) DEFAULT NULL,
  `pay_status` varchar(12) DEFAULT NULL,
  `paid_date` date DEFAULT NULL,
  `fk_id_bank_line` int(11) DEFAULT NULL,
  `paid_base_amount` decimal(14,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_grn`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_grn` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `grn_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_shipment` int(11) DEFAULT NULL,
  `fk_id_warehouse` int(11) DEFAULT NULL,
  `arrival_date` date DEFAULT NULL,
  `received_by` varchar(255) DEFAULT NULL,
  `grn_number` varchar(255) DEFAULT NULL,
  `grn_date` date DEFAULT NULL,
  `expected_units` decimal(15,4) DEFAULT 0.0000,
  `expected_cartons` decimal(15,4) DEFAULT 0.0000,
  `cartons_received` decimal(15,4) DEFAULT 0.0000,
  `units_received` decimal(15,4) DEFAULT 0.0000,
  `damaged_units` decimal(15,4) DEFAULT 0.0000,
  `missing_units` decimal(15,4) DEFAULT 0.0000,
  `shortage_qty` decimal(15,4) DEFAULT 0.0000,
  `damage_pct` decimal(15,4) DEFAULT 0.0000,
  `receiving_qc_status` varchar(255) DEFAULT NULL,
  `posting_status` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `approved_at` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `grn_source` varchar(12) NOT NULL DEFAULT 'PO',
  `claim_units` decimal(15,4) DEFAULT NULL,
  `claim_amount` decimal(14,2) DEFAULT NULL,
  `fk_id_claim_currency` int(11) DEFAULT NULL,
  `claim_base_amount` decimal(14,2) DEFAULT NULL,
  `claim_status` varchar(12) DEFAULT NULL,
  `claim_settled_as` varchar(20) DEFAULT NULL,
  `claim_reference` varchar(100) DEFAULT NULL,
  `claim_date` date DEFAULT NULL,
  `claim_credit_used` decimal(14,2) DEFAULT NULL,
  `claim_credit_remaining` decimal(14,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_grn_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_grn_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_grn` int(11) NOT NULL,
  `fk_id_po_line` int(11) DEFAULT NULL,
  `fk_id_product` int(11) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `expected_qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `accepted_qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `damaged_qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `received_qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `missing_qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `fk_id_location` int(11) NOT NULL,
  `units_still_in_stock` decimal(15,4) DEFAULT NULL,
  `claim_qty` decimal(15,4) DEFAULT NULL,
  `claim_amount` decimal(14,2) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_grn_line` (`fk_id_grn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='GRN lines; the GRN header totals are the sums of these lines (B37)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_inventory_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_inventory_receipts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `receipt_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_grn` int(11) DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `batch_number` varchar(255) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `qty_accepted` decimal(15,4) DEFAULT 0.0000,
  `qty_damaged` decimal(15,4) DEFAULT 0.0000,
  `landed_unit_cost` decimal(15,4) DEFAULT 0.0000,
  `inventory_value` decimal(15,4) DEFAULT 0.0000,
  `location` varchar(255) DEFAULT NULL,
  `bin_rack` varchar(255) DEFAULT NULL,
  `posting_date` date DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fk_id_location` int(11) DEFAULT NULL,
  `fk_id_product` int(11) DEFAULT NULL,
  `fk_id_lot` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_investments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_investments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `invest_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_investor` int(11) DEFAULT NULL,
  `fk_id_po` int(11) DEFAULT NULL,
  `invest_amount` decimal(15,4) DEFAULT 0.0000,
  `fk_id_currency` int(11) DEFAULT NULL,
  `exchange_rate` decimal(15,4) DEFAULT 0.0000,
  `base_amount` decimal(15,4) DEFAULT 0.0000,
  `invest_type` varchar(255) DEFAULT NULL,
  `share_pct` decimal(15,4) DEFAULT 0.0000,
  `invest_date` date DEFAULT NULL,
  `txn_ref` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_investors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_investors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `investor_code` varchar(255) DEFAULT NULL,
  `investor_name` varchar(255) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_landed_cost`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_landed_cost` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `lc_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_po` int(11) DEFAULT NULL,
  `product_cost` decimal(15,4) DEFAULT 0.0000,
  `packaging_cost` decimal(15,4) DEFAULT 0.0000,
  `inspection_cost` decimal(15,4) DEFAULT 0.0000,
  `origin_cost` decimal(15,4) DEFAULT 0.0000,
  `freight_cost` decimal(15,4) DEFAULT 0.0000,
  `insurance_cost` decimal(15,4) DEFAULT 0.0000,
  `customs_duty` decimal(15,4) DEFAULT 0.0000,
  `destination_cost` decimal(15,4) DEFAULT 0.0000,
  `last_mile_cost` decimal(15,4) DEFAULT 0.0000,
  `other_cost` decimal(15,4) DEFAULT 0.0000,
  `freight_method` varchar(255) DEFAULT NULL,
  `duty_method` varchar(255) DEFAULT NULL,
  `total_cost` decimal(15,4) DEFAULT 0.0000,
  `accepted_qty` decimal(15,4) DEFAULT 0.0000,
  `landed_cost_per_unit` decimal(15,4) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `run_version` smallint(6) DEFAULT NULL,
  `landed_status` varchar(12) DEFAULT NULL,
  `superseded_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_ownership_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_ownership_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `ot_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_cycle_product` int(11) DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `fk_id_owner_from` int(11) DEFAULT NULL,
  `fk_id_owner_to` int(11) DEFAULT NULL,
  `transfer_qty` decimal(15,4) DEFAULT 0.0000,
  `transfer_value` decimal(15,4) DEFAULT 0.0000,
  `transfer_date` date DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fk_id_lot` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_payment_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_payment_terms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_po` int(11) DEFAULT NULL,
  `term_no` decimal(15,4) DEFAULT 0.0000,
  `milestone` varchar(255) DEFAULT NULL,
  `pct` decimal(15,4) DEFAULT 0.0000,
  `planned_amount` decimal(15,4) DEFAULT 0.0000,
  `paid_amount` decimal(15,4) DEFAULT 0.0000,
  `remaining_amount` decimal(15,4) DEFAULT 0.0000,
  `completion_pct` decimal(15,4) DEFAULT 0.0000,
  `status` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `due_date` date DEFAULT NULL,
  `due_rule` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `payment_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_po` int(11) DEFAULT NULL,
  `fk_id_supplier` int(11) DEFAULT NULL,
  `fk_id_term` int(11) DEFAULT NULL,
  `payment_type` varchar(255) DEFAULT NULL,
  `payment_method` varchar(255) DEFAULT NULL,
  `payment_amount` decimal(15,4) DEFAULT 0.0000,
  `fk_id_currency` int(11) DEFAULT NULL,
  `exchange_rate` decimal(15,4) DEFAULT 0.0000,
  `base_amount` decimal(15,4) DEFAULT 0.0000,
  `payment_date` date DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `txn_ref` varchar(255) DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `approved_at` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `paid_via` varchar(12) NOT NULL DEFAULT 'BANK',
  `fk_id_bank_line` int(11) DEFAULT NULL,
  `fk_id_grn` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_platform_alloc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_platform_alloc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `pa_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `fk_id_warehouse` int(11) DEFAULT NULL,
  `fk_id_marketplace` int(11) DEFAULT NULL,
  `seller_account` varchar(255) DEFAULT NULL,
  `fk_id_cycle_product` int(11) DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `allocated_qty` decimal(15,4) DEFAULT 0.0000,
  `confirmed_qty` decimal(15,4) DEFAULT 0.0000,
  `actual_platform_qty` decimal(15,4) DEFAULT 0.0000,
  `status` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_po`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_po` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `po_code` varchar(255) DEFAULT NULL,
  `po_number` varchar(255) DEFAULT NULL,
  `po_date` date DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_supplier` int(11) DEFAULT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  `fk_id_payment_term` int(11) DEFAULT NULL,
  `fk_id_incoterm` int(11) DEFAULT NULL,
  `expected_production_date` date DEFAULT NULL,
  `required_ship_date` date DEFAULT NULL,
  `po_total` decimal(15,4) DEFAULT 0.0000,
  `deposit_amount` decimal(15,4) DEFAULT 0.0000,
  `balance_amount` decimal(15,4) DEFAULT 0.0000,
  `requested_by` int(11) DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `approval_date` date DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `exchange_rate` decimal(18,8) DEFAULT NULL,
  `deposit_pct` decimal(5,2) DEFAULT NULL,
  `f_paid_in_full` tinyint(4) NOT NULL DEFAULT 0,
  `fk_id_repeat_from_po` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_po_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_po_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_po` int(11) DEFAULT NULL,
  `fk_id_cycle_product` int(11) DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `qty_ordered` decimal(15,4) DEFAULT 0.0000,
  `qty_confirmed` decimal(15,4) DEFAULT 0.0000,
  `unit_price` decimal(15,4) DEFAULT 0.0000,
  `discount` decimal(15,4) DEFAULT 0.0000,
  `other_charges` decimal(15,4) DEFAULT 0.0000,
  `line_total` decimal(15,4) DEFAULT 0.0000,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `line_type` varchar(20) NOT NULL DEFAULT 'PRODUCT',
  `line_notes` varchar(500) DEFAULT NULL,
  `fk_id_product` int(11) DEFAULT NULL,
  `fk_id_sample` int(11) DEFAULT NULL,
  `fk_id_default_branch` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_production`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_production` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `prod_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_po` int(11) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `expected_completion` date DEFAULT NULL,
  `planned_qty` decimal(15,4) DEFAULT 0.0000,
  `produced_qty` decimal(15,4) DEFAULT 0.0000,
  `rejected_qty` decimal(15,4) DEFAULT 0.0000,
  `accepted_qty` decimal(15,4) DEFAULT 0.0000,
  `progress_pct` decimal(15,4) DEFAULT 0.0000,
  `status` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `variance_qty` decimal(15,4) DEFAULT 0.0000,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `parent_sku` varchar(255) DEFAULT NULL,
  `variation` varchar(255) DEFAULT NULL,
  `fk_id_brand` int(11) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `material` varchar(255) DEFAULT NULL,
  `size` varchar(255) DEFAULT NULL,
  `color` varchar(255) DEFAULT NULL,
  `unit_net_weight` decimal(15,4) DEFAULT 0.0000,
  `unit_gross_weight` decimal(15,4) DEFAULT 0.0000,
  `hs_code` varchar(255) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `units_per_carton` decimal(15,4) DEFAULT 0.0000,
  `carton_l` decimal(15,4) DEFAULT 0.0000,
  `carton_w` decimal(15,4) DEFAULT 0.0000,
  `carton_h` decimal(15,4) DEFAULT 0.0000,
  `carton_gross_weight` decimal(15,4) DEFAULT 0.0000,
  `carton_cbm` decimal(15,4) DEFAULT 0.0000,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fk_id_product` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_qc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_qc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `qc_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_po` int(11) DEFAULT NULL,
  `fk_id_supplier` int(11) DEFAULT NULL,
  `inspection_type` varchar(255) DEFAULT NULL,
  `inspector` varchar(255) DEFAULT NULL,
  `inspection_date` date DEFAULT NULL,
  `qty_submitted` decimal(15,4) DEFAULT 0.0000,
  `qty_inspected` decimal(15,4) DEFAULT 0.0000,
  `qty_passed` decimal(15,4) DEFAULT 0.0000,
  `qty_failed` decimal(15,4) DEFAULT 0.0000,
  `defect_rate` decimal(15,4) DEFAULT 0.0000,
  `qc_result` varchar(255) DEFAULT NULL,
  `qc_approval` varchar(255) DEFAULT NULL,
  `corrective_action` text DEFAULT NULL,
  `report_ref` varchar(255) DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `approved_at` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `re_inspection_of` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_qc_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_qc_checks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_qc` int(11) NOT NULL,
  `fk_id_product` int(11) DEFAULT NULL,
  `check_text` varchar(200) NOT NULL,
  `check_result` varchar(4) NOT NULL DEFAULT 'NA',
  `fk_id_photo_doc` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `sort_no` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_qcc` (`fk_id_qc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='QC checklist lines with photo and result (R-B_SOURCING-65)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_rfq`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_rfq` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `rfq_code` varchar(255) DEFAULT NULL,
  `rfq_date` date DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_supplier` int(11) DEFAULT NULL,
  `qty` decimal(15,4) DEFAULT 0.0000,
  `moq` decimal(15,4) DEFAULT 0.0000,
  `quoted_unit_price` decimal(15,4) DEFAULT 0.0000,
  `fk_id_currency` int(11) DEFAULT NULL,
  `tooling_cost` decimal(15,4) DEFAULT 0.0000,
  `sample_cost` decimal(15,4) DEFAULT 0.0000,
  `packaging_cost` decimal(15,4) DEFAULT 0.0000,
  `final_unit_price` decimal(15,4) DEFAULT 0.0000,
  `fk_id_incoterm` int(11) DEFAULT NULL,
  `fk_id_payment_term` int(11) DEFAULT NULL,
  `lead_time_days` decimal(15,4) DEFAULT 0.0000,
  `status` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `d_valid_until` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_samples`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_samples` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `sample_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_supplier` int(11) DEFAULT NULL,
  `fk_id_cycle_product` int(11) DEFAULT NULL,
  `sample_date` date DEFAULT NULL,
  `sample_qty` decimal(15,4) DEFAULT 0.0000,
  `sample_cost` decimal(15,4) DEFAULT 0.0000,
  `shipping_cost` decimal(15,4) DEFAULT 0.0000,
  `tracking_number` varchar(255) DEFAULT NULL,
  `received_date` date DEFAULT NULL,
  `approval_status` varchar(255) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `approved_at` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fk_id_currency` int(11) DEFAULT NULL,
  `exchange_rate` decimal(18,6) DEFAULT NULL,
  `base_amount` decimal(14,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_shipment_po_link`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_shipment_po_link` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_shipment` int(11) NOT NULL,
  `fk_id_po` int(11) NOT NULL,
  `planned_qty` decimal(15,4) DEFAULT 0.0000,
  `actual_qty` decimal(15,4) DEFAULT 0.0000,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fk_id_po_line` int(11) DEFAULT NULL,
  `fk_id_product` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_link` (`fk_id_shipment`,`fk_id_po`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_shipments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `shipment_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_po` int(11) DEFAULT NULL,
  `fk_id_supplier` int(11) DEFAULT NULL,
  `fk_id_country_origin` int(11) DEFAULT NULL,
  `origin_city` varchar(255) DEFAULT NULL,
  `container_number` varchar(255) DEFAULT NULL,
  `actual_departure` date DEFAULT NULL,
  `cargo_ready_date` date DEFAULT NULL,
  `planned_qty` decimal(15,4) DEFAULT 0.0000,
  `confirmed_qty` decimal(15,4) DEFAULT 0.0000,
  `actual_shipped_qty` decimal(15,4) DEFAULT 0.0000,
  `planned_cartons` decimal(15,4) DEFAULT 0.0000,
  `actual_cartons` decimal(15,4) DEFAULT 0.0000,
  `total_gross_weight` decimal(15,4) DEFAULT 0.0000,
  `total_cbm` decimal(15,4) DEFAULT 0.0000,
  `pickup_location` varchar(255) DEFAULT NULL,
  `fk_id_warehouse` int(11) DEFAULT NULL,
  `shipping_mode` varchar(255) DEFAULT NULL,
  `fcl_lcl` varchar(255) DEFAULT NULL,
  `container_type` varchar(255) DEFAULT NULL,
  `booking_number` varchar(255) DEFAULT NULL,
  `fk_id_port_loading` int(11) DEFAULT NULL,
  `fk_id_port_discharge` int(11) DEFAULT NULL,
  `etd` date DEFAULT NULL,
  `eta` date DEFAULT NULL,
  `actual_arrival` date DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fk_id_forwarder` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `supplier_code` varchar(30) DEFAULT NULL,
  `supplier_name` varchar(200) NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  `phone_whatsapp` varchar(40) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `wechat` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `supplier_type` varchar(100) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `fk_id_marketplace` int(11) DEFAULT NULL,
  `verification_status` varchar(100) DEFAULT NULL,
  `risk_level` varchar(100) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `address` text DEFAULT NULL,
  `website` varchar(300) DEFAULT NULL,
  `sourcing_platform` varchar(100) DEFAULT NULL,
  `reg_no` varchar(60) DEFAULT NULL,
  `vat_no` varchar(40) DEFAULT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  `fk_id_payment_term` int(11) DEFAULT NULL,
  `fk_id_incoterm` int(11) DEFAULT NULL,
  `production_lead_days` smallint(6) DEFAULT NULL,
  `shipping_lead_days` smallint(6) DEFAULT NULL,
  `moq` int(11) DEFAULT NULL,
  `sample_policy` varchar(100) DEFAULT NULL,
  `payment_method` varchar(100) DEFAULT NULL,
  `f_local` tinyint(4) NOT NULL DEFAULT 0,
  `years_in_business` smallint(6) DEFAULT NULL,
  `contact_role` varchar(80) DEFAULT NULL,
  `fk_id_language` int(11) DEFAULT NULL,
  `timezone` varchar(64) DEFAULT NULL,
  `warranty_terms` varchar(255) DEFAULT NULL,
  `d_last_audit` date DEFAULT NULL,
  `rating` varchar(2) DEFAULT NULL,
  `certifications` varchar(500) DEFAULT NULL,
  `product_categories` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Supplier master (one row per supplier; no name / contact / phone duplicates)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_src_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_src_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) DEFAULT NULL,
  `transfer_code` varchar(255) DEFAULT NULL,
  `fk_id_cycle` int(11) DEFAULT NULL,
  `fk_id_warehouse_src` int(11) DEFAULT NULL,
  `fk_id_country_src` int(11) DEFAULT NULL,
  `fk_id_country_dest` int(11) DEFAULT NULL,
  `fk_id_warehouse_dest` int(11) DEFAULT NULL,
  `fk_id_marketplace` int(11) DEFAULT NULL,
  `seller_account` varchar(255) DEFAULT NULL,
  `fk_id_cycle_product` int(11) DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `qty_requested` decimal(15,4) DEFAULT 0.0000,
  `qty_confirmed` decimal(15,4) DEFAULT 0.0000,
  `qty_dispatched` decimal(15,4) DEFAULT 0.0000,
  `qty_received` decimal(15,4) DEFAULT 0.0000,
  `qty_in_transit` decimal(15,4) DEFAULT 0.0000,
  `qty_accepted` decimal(15,4) DEFAULT 0.0000,
  `qty_damaged` decimal(15,4) DEFAULT 0.0000,
  `qty_missing` decimal(15,4) DEFAULT 0.0000,
  `transfer_cost` decimal(15,4) DEFAULT 0.0000,
  `transfer_date` date DEFAULT NULL,
  `expected_arrival` date DEFAULT NULL,
  `actual_arrival` date DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `f_active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_stock_count_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_stock_count_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_count` int(11) NOT NULL,
  `fk_id_product` int(11) NOT NULL,
  `qty_system` int(11) NOT NULL,
  `qty_counted` int(11) DEFAULT NULL,
  `fk_id_reason` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `fk_id_location` int(11) DEFAULT NULL,
  `snapshot_at` datetime DEFAULT NULL,
  `qty_moved_since` int(11) NOT NULL DEFAULT 0,
  `qty_recount` int(11) DEFAULT NULL,
  `counted_by` int(11) DEFAULT NULL,
  `recounted_by` int(11) DEFAULT NULL,
  `line_status` varchar(12) NOT NULL DEFAULT 'OPEN',
  PRIMARY KEY (`id`),
  KEY `ix_count` (`fk_id_count`),
  KEY `ix_cntline_loc` (`fk_id_location`,`fk_id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_stock_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_stock_counts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `count_code` varchar(30) NOT NULL,
  `fk_id_location` int(11) DEFAULT NULL,
  `d_count` date NOT NULL,
  `status` enum('OPEN','SUBMITTED','APPROVED','POSTED','CANCELLED') NOT NULL DEFAULT 'OPEN',
  `counted_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `count_type` varchar(12) NOT NULL DEFAULT 'BIN',
  `fk_id_site` int(11) DEFAULT NULL,
  `fk_id_zone` int(11) DEFAULT NULL,
  `abc_class` char(1) DEFAULT NULL,
  `trigger_source` varchar(20) DEFAULT NULL,
  `f_blind` tinyint(4) NOT NULL DEFAULT 1,
  `variance_value` decimal(14,2) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `count_code` (`count_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_stock_movements` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_product` int(11) NOT NULL,
  `movement_type` enum('opening','purchase_in','sale_out','adjustment','return_in','transfer_out','transfer_in','ownership_out','ownership_in','damaged','count_adjust','production','in_transit','reserve','unreserve','written_off') NOT NULL,
  `qty_change` int(11) NOT NULL COMMENT 'positive = in, negative = out',
  `stock_after` int(11) NOT NULL DEFAULT 0 COMMENT 'stock value after this movement',
  `fk_id_reference` int(11) DEFAULT NULL COMMENT 'purchase id / sale row id',
  `fk_id_reason` int(11) DEFAULT NULL COMMENT 'FK -> cat_adjustment_reasons (adjustments)',
  `fk_id_marketplace` int(11) DEFAULT NULL COMMENT 'for sale_out rows',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_location` int(11) DEFAULT NULL,
  `fk_id_lot` int(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `unit_cost_gbp` decimal(12,4) DEFAULT NULL,
  `source_type` varchar(30) DEFAULT NULL,
  `source_id` bigint(20) DEFAULT NULL,
  `pair_id` bigint(20) DEFAULT NULL,
  `movement_code` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product_date` (`fk_id_product`,`created_at`),
  KEY `idx_type` (`movement_type`),
  KEY `ix_prod_loc` (`fk_id_product`,`fk_id_location`),
  KEY `ix_source` (`source_type`,`source_id`),
  KEY `ix_lot` (`fk_id_lot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_tiktok_sale`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_tiktok_sale` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `fk_id_shop` int(11) NOT NULL,
  `d_order_date` date NOT NULL,
  `order_no` varchar(255) DEFAULT NULL,
  `custom_field_1` varchar(200) DEFAULT NULL,
  `custom_field_2` varchar(200) DEFAULT NULL,
  `custom_field_3` varchar(200) DEFAULT NULL,
  `buyer_full_name` varchar(200) DEFAULT NULL,
  `buyer_first_name` varchar(100) DEFAULT NULL,
  `buyer_last_name` varchar(100) DEFAULT NULL,
  `buyer_email` varchar(150) DEFAULT NULL,
  `buyer_phone` varchar(100) DEFAULT NULL,
  `buyer_city` varchar(100) DEFAULT NULL,
  `buyer_state` varchar(100) DEFAULT NULL,
  `buyer_postal_code` varchar(100) DEFAULT NULL,
  `item_sku` varchar(200) DEFAULT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `item_qty` int(11) NOT NULL,
  `f_status` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=pending,1=done',
  `order_carrier` varchar(255) NOT NULL,
  `order_tracking` varchar(255) DEFAULT NULL,
  `recipient_first_name` varchar(150) DEFAULT NULL,
  `recipient_last_name` varchar(150) DEFAULT NULL,
  `recipient_phone` varchar(100) DEFAULT NULL,
  `address_line_1` varchar(200) DEFAULT NULL,
  `address_line_2` varchar(200) DEFAULT NULL,
  `address_line_3` varchar(200) DEFAULT NULL,
  `country_name` varchar(100) DEFAULT NULL,
  `country_code` varchar(50) DEFAULT NULL,
  `length_cm` varchar(5) NOT NULL DEFAULT '0',
  `width_cm` varchar(5) NOT NULL DEFAULT '0',
  `height_cm` varchar(5) NOT NULL DEFAULT '0',
  `wight_gr` varchar(5) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_user_column_prefs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_user_column_prefs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_user` int(11) NOT NULL COMMENT 't_users.id (logged-in user)',
  `page_key` varchar(50) NOT NULL COMMENT 'e.g. sales_listing',
  `hidden_columns` text NOT NULL COMMENT 'JSON array of hidden column keys',
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_page` (`fk_id_user`,`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login_id` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `pass` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `fk_id_account` int(11) NOT NULL,
  `f_active` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_id_account_in_users` (`fk_id_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_variation_marketplace_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_variation_marketplace_prices` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `fk_id_variation` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL,
  `raw_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sale_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `list_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `marketplace_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_variation_mp` (`fk_id_variation`,`fk_id_marketplace`),
  UNIQUE KEY `uk_variation_marketplace` (`fk_id_variation`,`fk_id_marketplace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_variation_marketplace_shipping`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_variation_marketplace_shipping` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `fk_id_variation` int(11) NOT NULL,
  `fk_id_marketplace` int(11) NOT NULL,
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `fk_id_packing` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_variation_mp` (`fk_id_variation`,`fk_id_marketplace`),
  UNIQUE KEY `uk_variation_marketplace` (`fk_id_variation`,`fk_id_marketplace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `t_vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_vendors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `vendor_name` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `iso_vendor` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `f_local` tinyint(1) NOT NULL,
  `f_use_same_sku` tinyint(4) NOT NULL,
  `f_active` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fk_id_ent` (`fk_id_ent`,`vendor_name`),
  KEY `idx_vendor_ent` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `task_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `default_owner_user_id` int(11) DEFAULT NULL,
  `default_due_days` int(11) NOT NULL DEFAULT 3,
  `ack_hours` decimal(6,2) NOT NULL DEFAULT 24.00,
  `escalate_to_role` enum('MANAGER','PARTNER') NOT NULL DEFAULT 'MANAGER',
  `evidence_type_default` enum('SCREENSHOT','PHOTO','FILE','LINK','REFERENCE') NOT NULL DEFAULT 'SCREENSHOT',
  `sort` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `code` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`),
  UNIQUE KEY `uq_task_cat_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `task_categories` VALUES
(1,'General (manual tasks only)',NULL,3,24.00,'MANAGER','SCREENSHOT',10,1,'GENERAL'),
(2,'Sales',NULL,2,12.00,'MANAGER','SCREENSHOT',20,1,'SALES'),
(3,'Listings',NULL,3,24.00,'MANAGER','SCREENSHOT',30,1,'LISTING'),
(4,'Prices and fees',NULL,2,24.00,'PARTNER','SCREENSHOT',40,1,'PRICING'),
(5,'Sourcing',NULL,2,12.00,'PARTNER','FILE',50,1,'SOURCING'),
(6,'Stock',NULL,2,12.00,'MANAGER','PHOTO',60,1,'STOCK'),
(7,'Labels and shipping',NULL,0,4.00,'MANAGER','PHOTO',70,1,'LABELS'),
(8,'Dropship',NULL,2,24.00,'MANAGER','SCREENSHOT',80,1,'DROPSHIP'),
(9,'Imports',NULL,1,12.00,'MANAGER','SCREENSHOT',90,1,'IMPORTS'),
(10,'Finance',NULL,2,12.00,'PARTNER','FILE',100,1,'FINANCE'),
(11,'Invoicing',NULL,3,24.00,'PARTNER','FILE',110,1,'INVOICING'),
(12,'People and accounts kit',NULL,3,24.00,'MANAGER','REFERENCE',120,1,'PEOPLE'),
(13,'Company compliance',NULL,5,24.00,'PARTNER','FILE',130,1,'COMPLIANCE'),
(14,'Shop health',NULL,2,12.00,'MANAGER','SCREENSHOT',140,1,'SHOP_HEALTH'),
(15,'Data quality',NULL,7,48.00,'MANAGER','REFERENCE',150,1,'DATA_QUALITY'),
(16,'System',NULL,1,4.00,'PARTNER','REFERENCE',160,1,'SYSTEM');
DROP TABLE IF EXISTS `task_checklist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_checklist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `text` varchar(255) NOT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT 0,
  `done_by` int(11) DEFAULT NULL,
  `done_at` datetime DEFAULT NULL,
  `sort` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `task_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `at` datetime NOT NULL,
  `user_id` int(11) NOT NULL,
  `text` text NOT NULL,
  `file_id` int(11) DEFAULT NULL COMMENT 'task_evidence.id of an attached file (type FILE/VOICE)',
  `mentions_json` varchar(500) DEFAULT NULL,
  `via` enum('APP','WHATSAPP') NOT NULL DEFAULT 'APP',
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `task_evidence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_evidence` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `type` enum('SCREENSHOT','PHOTO','FILE','LINK','REFERENCE','VOICE') NOT NULL,
  `document_id` int(11) DEFAULT NULL COMMENT 'Documents module id - NULL until that module exists',
  `file_path` varchar(255) DEFAULT NULL COMMENT 'uploads/documents/tasks/{task_id}/...',
  `file_name` varchar(255) DEFAULT NULL COMMENT 'original name as uploaded',
  `mime` varchar(100) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `reference_id` varchar(120) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `taken_at` datetime DEFAULT NULL COMMENT 'EXIF time when the phone gives it',
  `gps_lat` decimal(10,7) DEFAULT NULL,
  `gps_lng` decimal(10,7) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL DEFAULT 0,
  `via` enum('APP','WHATSAPP') NOT NULL DEFAULT 'APP',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `task_helpers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_helpers` (
  `task_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`task_id`,`user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `task_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `at` datetime NOT NULL,
  `user_id` int(11) NOT NULL DEFAULT 0 COMMENT '0 = system',
  `field` varchar(60) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `task_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `score` decimal(5,1) NOT NULL,
  `on_time_pts` int(11) NOT NULL DEFAULT 0,
  `evidence_pts` int(11) NOT NULL DEFAULT 0,
  `quality_pts` int(11) NOT NULL DEFAULT 0,
  `rework_pts` int(11) NOT NULL DEFAULT 0,
  `closed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_task` (`task_id`),
  KEY `idx_user_closed` (`user_id`,`closed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `task_template_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_template_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `category_id` int(11) NOT NULL,
  `owner_role` enum('PARTNER','MANAGER','VA','CATEGORY_DEFAULT') NOT NULL DEFAULT 'CATEGORY_DEFAULT',
  `offset_days` int(11) NOT NULL DEFAULT 0,
  `depends_on_item_id` int(11) DEFAULT NULL,
  `sort` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_template` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `task_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `linked_module` varchar(40) DEFAULT NULL,
  `trigger_event` enum('PO_CONFIRMED','GRN_SAVED','PERIOD_OPENED') DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `task_watchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_watchers` (
  `task_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`task_id`,`user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `owner_user_id` int(11) NOT NULL,
  `assigned_by_user_id` int(11) NOT NULL DEFAULT 0 COMMENT '0 = system',
  `priority` enum('URGENT','HIGH','NORMAL','LOW') NOT NULL DEFAULT 'NORMAL',
  `status` enum('TODO','DOING','STUCK','DONE','REWORK','APPROVED','CANCELLED') NOT NULL DEFAULT 'TODO',
  `acknowledged_at` datetime DEFAULT NULL,
  `stuck_on_type` enum('USER','SUPPLIER','MARKETPLACE','FORWARDER','OTHER') DEFAULT NULL,
  `stuck_on_id` int(11) DEFAULT NULL,
  `stuck_on_text` varchar(255) DEFAULT NULL COMMENT 'name of the party when not a user',
  `stuck_since` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `start_at` datetime DEFAULT NULL,
  `due_at` date NOT NULL,
  `due_changes` int(11) NOT NULL DEFAULT 0,
  `done_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by_user_id` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by_user_id` int(11) DEFAULT NULL,
  `evidence_required` tinyint(1) NOT NULL DEFAULT 1,
  `evidence_waived_by` int(11) DEFAULT NULL,
  `evidence_waived_at` datetime DEFAULT NULL,
  `quality` enum('EXCELLENT','GOOD','OK','POOR') DEFAULT NULL,
  `rework_count` int(11) NOT NULL DEFAULT 0,
  `source` enum('MANUAL','RULE','REPEAT','TEMPLATE') NOT NULL DEFAULT 'MANUAL',
  `rule_code` varchar(40) DEFAULT NULL,
  `dedupe_key` varchar(190) DEFAULT NULL COMMENT 'RULE tasks only; NULL for others',
  `import_ref` varchar(190) DEFAULT NULL COMMENT 'CSV import row key; NULL for others',
  `repeat_type` enum('NONE','DAILY','WEEKLY','MONTHLY','EVERY_N_DAYS') NOT NULL DEFAULT 'NONE',
  `repeat_n` int(11) DEFAULT NULL,
  `repeat_weekday` tinyint(4) DEFAULT NULL COMMENT '1=Mon..7=Sun',
  `repeat_day_of_month` tinyint(4) DEFAULT NULL,
  `repeat_until` date DEFAULT NULL,
  `repeat_parent_id` int(11) DEFAULT NULL,
  `template_id` int(11) DEFAULT NULL,
  `parent_task_id` int(11) DEFAULT NULL,
  `depends_on_task_id` int(11) DEFAULT NULL,
  `linked_module` varchar(40) DEFAULT NULL COMMENT 'CYCLE / PO / PRODUCT / SHIPMENT / IMPORT / SALE_BATCH / FIN_PERIOD',
  `linked_record_id` int(11) DEFAULT NULL,
  `reference_id` varchar(120) DEFAULT NULL COMMENT 'case ID, PI, shipment ID ...',
  `marketplace` int(11) DEFAULT NULL COMMENT 'cat_marketplaces.id',
  `brand_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `amount_gbp` decimal(14,2) DEFAULT NULL COMMENT 'Partner only - stripped by TaskService',
  `is_confidential` tinyint(1) NOT NULL DEFAULT 0,
  `flags` varchar(255) NOT NULL DEFAULT '' COMMENT 'comma list: DUE_MOVED,STALE,REPEAT_BLOCKED,ABSENT',
  `last_update_at` datetime DEFAULT NULL,
  `last_notified_at` datetime DEFAULT NULL,
  `score` decimal(5,1) DEFAULT NULL,
  `fk_id_event` bigint(20) DEFAULT NULL,
  `fix_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dedupe_key` (`dedupe_key`),
  UNIQUE KEY `uq_import_ref` (`import_ref`),
  KEY `idx_owner_status` (`owner_user_id`,`status`),
  KEY `idx_assigned_by` (`assigned_by_user_id`),
  KEY `idx_status_due` (`status`,`due_at`),
  KEY `idx_link` (`linked_module`,`linked_record_id`),
  KEY `idx_parent` (`parent_task_id`),
  KEY `idx_depends` (`depends_on_task_id`),
  KEY `idx_stuck_on` (`stuck_on_type`,`stuck_on_id`),
  KEY `idx_due` (`due_at`),
  KEY `idx_approved_at` (`approved_at`),
  KEY `idx_repeat_parent` (`repeat_parent_id`),
  KEY `idx_rule` (`source`,`rule_code`,`status`),
  KEY `idx_last_update` (`status`,`last_update_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tax_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `name` varchar(120) NOT NULL,
  `section` enum('BS','PL','ADJ') NOT NULL COMMENT 'BS=Balance sheet, PL=Profit & loss, ADJ=Tax computation adjustments',
  `icon` varchar(50) DEFAULT 'fa fa-folder',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tax_cat_code` (`fk_id_ent`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tax_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_period` int(11) NOT NULL,
  `fk_line_item` int(11) NOT NULL,
  `entry_date` date DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reference` varchar(100) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `source` varchar(30) NOT NULL DEFAULT 'MANUAL',
  `source_ref_id` varchar(60) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_tax_entry_period` (`fk_period`,`fk_line_item`),
  KEY `ix_tax_entry_source` (`source`,`source_ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tax_line_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_line_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_category` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `line_role` enum('FIXED_ASSET','CURRENT_ASSET','PREPAYMENT','CREDITOR_SHORT','CREDITOR_LONG','PROVISION','ACCRUAL','CAPITAL','TURNOVER','COST_OF_SALES','OTHER_INCOME','EXPENSE','TAX_ADD','TAX_DEDUCT') NOT NULL,
  `tax_treatment` enum('ALLOW','DISALLOW','NA') NOT NULL DEFAULT 'NA' COMMENT 'DISALLOW = added back in the CT computation',
  `ixbrl_tag` varchar(120) DEFAULT NULL COMMENT 'FRC taxonomy concept (verify before filing)',
  `guidance` varchar(500) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tax_li_code` (`fk_id_ent`,`code`),
  KEY `ix_tax_li_cat` (`fk_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tax_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_company_house` int(11) NOT NULL,
  `period_label` varchar(100) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `f_first_period` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'First accounts: CH deadline = incorporation + 21 months',
  `accounts_type` enum('MICRO','SMALL','DORMANT','FULL') NOT NULL DEFAULT 'MICRO',
  `associated_companies` int(11) NOT NULL DEFAULT 0 COMMENT 'Divides the £50k/£250k limits',
  `avg_employees` int(11) NOT NULL DEFAULT 0,
  `status` enum('DRAFT','PREPARED','ACCOUNTS_FILED','CT600_FILED','TAX_PAID','CLOSED') NOT NULL DEFAULT 'DRAFT',
  `accounts_filed_date` date DEFAULT NULL,
  `ct600_filed_date` date DEFAULT NULL,
  `tax_paid_date` date DEFAULT NULL,
  `tax_paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `ch_filing_ref` varchar(100) DEFAULT NULL,
  `hmrc_submission_ref` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tax_period` (`fk_company_house`,`period_start`),
  KEY `ix_tax_period_ent` (`fk_id_ent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tax_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fy_start_year` int(4) NOT NULL,
  `small_rate` decimal(5,2) NOT NULL,
  `main_rate` decimal(5,2) NOT NULL,
  `lower_limit` decimal(14,2) NOT NULL,
  `upper_limit` decimal(14,2) NOT NULL,
  `mr_fraction_num` int(11) NOT NULL DEFAULT 3,
  `mr_fraction_den` int(11) NOT NULL DEFAULT 200,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tax_rate_fy` (`fy_start_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `threepl_fee_buckets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `threepl_fee_buckets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_threepl_profile` int(11) NOT NULL,
  `bucket_code` varchar(30) NOT NULL,
  `label` varchar(120) NOT NULL,
  `fee_basis` varchar(20) NOT NULL,
  `rate` decimal(12,4) DEFAULT NULL,
  `charge_kind` varchar(30) NOT NULL,
  `sort_no` smallint(6) NOT NULL DEFAULT 0,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tplfb` (`fk_id_threepl_profile`,`bucket_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Fee buckets of a 3PL profile';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `threepl_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `threepl_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `fk_id_party` int(11) DEFAULT NULL,
  `fk_id_location` int(11) NOT NULL,
  `stock_report_type` varchar(40) DEFAULT NULL,
  `inbound_report_type` varchar(40) DEFAULT NULL,
  `fee_report_type` varchar(40) DEFAULT NULL,
  `cutoff_time` time DEFAULT NULL,
  `timezone` varchar(40) DEFAULT NULL,
  `overdue_days` smallint(6) DEFAULT NULL,
  `fk_id_currency` int(11) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tpl_code` (`fk_id_ent`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='3PL profile (IC-0.6)';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `threepl_profiles` VALUES
(1,0,'EX_3PL_A','Third-party warehouse A (example)',NULL,0,NULL,NULL,NULL,'14:00:00',NULL,2,NULL,1,'Example row for tests (IC-0.10); location set when copied',NULL,'2026-11-12 04:31:48',NULL,NULL),
(2,0,'EX_3PL_B','Third-party warehouse B (example)',NULL,0,NULL,NULL,NULL,'12:00:00',NULL,3,NULL,1,'Example row for tests (IC-0.10); location set when copied',NULL,'2026-11-12 04:31:48',NULL,NULL);
DROP TABLE IF EXISTS `tm_alert_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tm_alert_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `trigger_event` varchar(120) NOT NULL,
  `to_role_or_person` varchar(120) NOT NULL,
  `channels_json` varchar(120) NOT NULL DEFAULT '["INAPP"]',
  `when_rule` varchar(200) NOT NULL DEFAULT '',
  `escalation` varchar(200) NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tm_delegations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tm_delegations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_user_id` int(11) NOT NULL,
  `to_user_id` int(11) NOT NULL,
  `from_date` date NOT NULL,
  `to_date` date NOT NULL,
  `rights` enum('APPROVE','ALL') NOT NULL DEFAULT 'APPROVE',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_to_dates` (`to_user_id`,`from_date`,`to_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tm_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tm_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `task_id` int(11) DEFAULT NULL,
  `rule_code` varchar(40) DEFAULT NULL,
  `channel` enum('WHATSAPP','EMAIL','INAPP') NOT NULL,
  `to_address` varchar(200) DEFAULT NULL COMMENT 'WhatsApp number or email used',
  `direction` enum('OUT','IN') NOT NULL DEFAULT 'OUT',
  `template_key` varchar(60) DEFAULT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `template_params` text DEFAULT NULL COMMENT 'JSON list of template variables',
  `event_key` varchar(120) DEFAULT NULL COMMENT 'rule+task+day, for "never notify twice"',
  `text` text NOT NULL,
  `status` enum('QUEUED','SENT','DELIVERED','READ','FAILED','RECEIVED','IGNORED','ACTED') NOT NULL DEFAULT 'QUEUED',
  `send_after` datetime DEFAULT NULL COMMENT 'quiet hours: not before this time',
  `provider_id` varchar(120) DEFAULT NULL,
  `context_id` varchar(120) DEFAULT NULL COMMENT 'inbound: provider id of the message being replied to',
  `action` varchar(60) DEFAULT NULL COMMENT 'inbound: what the reply did (ACK, DONE, EVIDENCE, REFUSED ...)',
  `sent_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `retry_count` int(11) NOT NULL DEFAULT 0,
  `error` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_channel` (`event_key`,`channel`),
  KEY `idx_task` (`task_id`),
  KEY `idx_provider` (`provider_id`),
  KEY `idx_queue` (`status`,`send_after`),
  KEY `idx_created` (`created_at`),
  KEY `idx_user_dir` (`user_id`,`direction`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tm_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tm_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `task_id` int(11) DEFAULT NULL,
  `type` varchar(20) NOT NULL,
  `text` varchar(500) NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tm_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tm_settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` varchar(120) NOT NULL,
  `label` varchar(160) NOT NULL DEFAULT '',
  `sort` int(11) NOT NULL DEFAULT 0,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tm_trigger_seen`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tm_trigger_seen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event` varchar(30) NOT NULL,
  `record_id` int(11) NOT NULL,
  `result` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_record` (`event`,`record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `user_absence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_absence` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `from_date` date NOT NULL,
  `to_date` date NOT NULL,
  `cover_user_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_dates` (`user_id`,`from_date`,`to_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_account_rights_01`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_account_rights_01` (
  `id_acc_role` int(11) DEFAULT NULL,
  `fk_id_account` int(11) DEFAULT NULL,
  `fk_id_role` int(11) DEFAULT NULL,
  `role_name` varchar(500) DEFAULT NULL,
  `iso_role` varchar(10) DEFAULT NULL,
  `f_active_acc_role` int(11) DEFAULT NULL,
  `f_active_role` int(11) DEFAULT NULL,
  `id_role_right` int(11) DEFAULT NULL,
  `fk_id_right` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `right_name` varchar(100) DEFAULT NULL,
  `iso_right` varchar(10) DEFAULT NULL,
  `f_active_right` int(11) DEFAULT NULL,
  `f_active_role_right` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_account_rights_02`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_account_rights_02` (
  `fk_id_account` int(11) DEFAULT NULL,
  `fk_id_right` int(11) DEFAULT NULL,
  `right_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_account_rights_final`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_account_rights_final` (
  `fk_id_account` int(11) DEFAULT NULL,
  `fk_id_right` int(11) DEFAULT NULL,
  `right_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_account_types_grouped`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_account_types_grouped` (
  `id` int(11) DEFAULT NULL,
  `account_types` mediumtext DEFAULT NULL,
  `iso_account_types` mediumtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_ars_audit_inv_parent_disappear_child_active`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_ars_audit_inv_parent_disappear_child_active` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `product_name_vendor` varchar(255) DEFAULT NULL,
  `ean` varchar(20) DEFAULT NULL,
  `fk_id_product_type` int(11) DEFAULT NULL,
  `fk_id_product_trend` int(11) DEFAULT NULL,
  `fk_id_product_vendor_self` int(11) DEFAULT NULL,
  `variant` varchar(70) DEFAULT NULL,
  `d_created` date DEFAULT NULL,
  `fk_id_country_make` int(11) DEFAULT NULL,
  `weight` varchar(50) DEFAULT NULL,
  `dimensions` varchar(15) DEFAULT NULL,
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `fk_id_packing` int(11) DEFAULT NULL,
  `url_ebay` varchar(500) DEFAULT NULL,
  `ebay_fee` decimal(10,2) DEFAULT NULL,
  `sale_price_with_vat` decimal(10,2) DEFAULT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `list_price` decimal(10,2) DEFAULT NULL,
  `stock` int(11) DEFAULT NULL,
  `f_out_of_stock` tinyint(4) DEFAULT NULL,
  `min_level` int(11) DEFAULT NULL,
  `f_alert_min_level` tinyint(1) DEFAULT NULL,
  `href_product` varchar(255) DEFAULT NULL,
  `SKU_VENDOR` varchar(50) DEFAULT NULL,
  `opc` varchar(50) DEFAULT NULL,
  `upc` varchar(50) DEFAULT NULL,
  `fk_id_account_added_by` int(11) DEFAULT NULL,
  `fk_id_account_updated_by` int(11) DEFAULT NULL,
  `f_active` tinyint(1) DEFAULT NULL,
  `carrier` varchar(500) DEFAULT NULL,
  `f_tag1` tinyint(4) DEFAULT NULL,
  `f_tag2` tinyint(4) DEFAULT NULL,
  `f_tag3` tinyint(4) DEFAULT NULL,
  `d_product_expiry_date` date DEFAULT NULL,
  `fk_id_role` int(11) DEFAULT NULL,
  `fk_id_role_account` int(11) DEFAULT NULL,
  `fk_id_vendor_inventory` int(11) DEFAULT NULL,
  `f_parent` tinyint(4) DEFAULT NULL,
  `f_id_parent` int(11) DEFAULT NULL,
  `f_color` varchar(50) DEFAULT NULL,
  `f_size` varchar(20) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `f_group_name` varchar(255) DEFAULT NULL,
  `product_img` varchar(100) DEFAULT NULL,
  `hs_code` varchar(255) DEFAULT NULL,
  `product_description` mediumtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_ars_audit_inv_products_family_alone`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_ars_audit_inv_products_family_alone` (
  `fk_id_product_vendor_self` int(11) DEFAULT NULL,
  `f_parent` tinyint(4) DEFAULT NULL,
  `total` bigint(21) DEFAULT NULL,
  `tot_active` decimal(22,0) DEFAULT NULL,
  `tot_active_avaiable` decimal(22,0) DEFAULT NULL,
  `tot_active_out_of_stock` decimal(22,0) DEFAULT NULL,
  `tot_disappeared` decimal(22,0) DEFAULT NULL,
  `tot_disappeared_avaiable` decimal(22,0) DEFAULT NULL,
  `tot_disappeared_out_of_stock` decimal(22,0) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_ars_table_change_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_ars_table_change_log` (
  `TABLE_CATALOG` varchar(512) DEFAULT NULL,
  `TABLE_SCHEMA` varchar(64) DEFAULT NULL,
  `TABLE_NAME` varchar(64) DEFAULT NULL,
  `TABLE_TYPE` varchar(64) DEFAULT NULL,
  `ENGINE` varchar(64) DEFAULT NULL,
  `VERSION` bigint(21) unsigned DEFAULT NULL,
  `ROW_FORMAT` varchar(10) DEFAULT NULL,
  `TABLE_ROWS` bigint(21) unsigned DEFAULT NULL,
  `AVG_ROW_LENGTH` bigint(21) unsigned DEFAULT NULL,
  `DATA_LENGTH` bigint(21) unsigned DEFAULT NULL,
  `MAX_DATA_LENGTH` bigint(21) unsigned DEFAULT NULL,
  `INDEX_LENGTH` bigint(21) unsigned DEFAULT NULL,
  `DATA_FREE` bigint(21) unsigned DEFAULT NULL,
  `AUTO_INCREMENT` bigint(21) unsigned DEFAULT NULL,
  `CREATE_TIME` datetime DEFAULT NULL,
  `UPDATE_TIME` datetime DEFAULT NULL,
  `CHECK_TIME` datetime DEFAULT NULL,
  `TABLE_COLLATION` varchar(32) DEFAULT NULL,
  `CHECKSUM` bigint(21) unsigned DEFAULT NULL,
  `CREATE_OPTIONS` varchar(2048) DEFAULT NULL,
  `TABLE_COMMENT` varchar(2048) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_audit_Qry_invalid_item_note`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_audit_Qry_invalid_item_note` (
  `id` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `ebay_order_no` varchar(30) DEFAULT NULL,
  `d_ebay_order` datetime DEFAULT NULL,
  `cust_name` varchar(250) DEFAULT NULL,
  `cust_phone` varchar(100) DEFAULT NULL,
  `cust_address` varchar(1500) DEFAULT NULL,
  `fk_id_product_branch` int(11) DEFAULT NULL,
  `f_local` int(11) DEFAULT NULL,
  `qty_ordered` int(11) DEFAULT NULL,
  `sale_rate` decimal(10,2) DEFAULT NULL,
  `c_tot_amount` decimal(10,2) DEFAULT NULL,
  `c_tot_discounts` decimal(10,2) DEFAULT NULL,
  `c_tot_add` decimal(10,2) DEFAULT NULL,
  `c_grand_total` decimal(11,2) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_ref_no` varchar(50) DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `item_note` varchar(100) DEFAULT NULL,
  `sku_ebay` varchar(100) DEFAULT NULL,
  `reason_by_va` varchar(250) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_audit_Qry_invalid_item_note_in_ebay_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_audit_Qry_invalid_item_note_in_ebay_audit` (
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `sku_ebay` varchar(100) DEFAULT NULL,
  `Sales_record_number` int(11) DEFAULT NULL,
  `Order_number` varchar(50) DEFAULT NULL,
  `Buyer_username` varchar(25) DEFAULT NULL,
  `Buyer_name` varchar(150) DEFAULT NULL,
  `Buyer_email` varchar(150) DEFAULT NULL,
  `Buyer_note` varchar(100) DEFAULT NULL,
  `Buyer_address_1` varchar(250) DEFAULT NULL,
  `Buyer_address_2` varchar(250) DEFAULT NULL,
  `Buyer_city` varchar(150) DEFAULT NULL,
  `Buyer_county` varchar(150) DEFAULT NULL,
  `Buyer_postcode` varchar(50) DEFAULT NULL,
  `Buyer_country` varchar(150) DEFAULT NULL,
  `Buyer_tax_identifier_name` varchar(100) DEFAULT NULL,
  `Buyer_tax_identifier_value` varchar(100) DEFAULT NULL,
  `Post_to_name` varchar(100) DEFAULT NULL,
  `Post_to_phone` varchar(100) DEFAULT NULL,
  `Post_to_address_1` varchar(100) DEFAULT NULL,
  `Post_to_address_2` varchar(100) DEFAULT NULL,
  `Post_to_city` varchar(100) DEFAULT NULL,
  `Post_to_county` varchar(100) DEFAULT NULL,
  `Post_to_postcode` varchar(100) DEFAULT NULL,
  `Post_to_country` varchar(100) DEFAULT NULL,
  `Item_number` varchar(30) DEFAULT NULL,
  `Item_title` varchar(250) DEFAULT NULL,
  `Custom_label` varchar(100) DEFAULT NULL,
  `Sold_via_Promoted_listings` varchar(10) DEFAULT NULL,
  `Quantity` int(11) DEFAULT NULL,
  `Sold_for` decimal(10,2) DEFAULT NULL,
  `Postage_and_packaging` varchar(10) DEFAULT NULL,
  `Item_location` varchar(10) DEFAULT NULL,
  `Item_postcode` varchar(10) DEFAULT NULL,
  `Item_country` varchar(10) DEFAULT NULL,
  `Included_VAT_rate` varchar(10) DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Rate` varchar(10) DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Type` varchar(10) DEFAULT NULL,
  `eBay_reference_name` varchar(10) DEFAULT NULL,
  `eBay_reference_value` varchar(10) DEFAULT NULL,
  `Tax_status` varchar(10) DEFAULT NULL,
  `Seller_collected_tax` varchar(10) DEFAULT NULL,
  `eBay_collected_tax` varchar(10) DEFAULT NULL,
  `Electronic_waste_recycling_fee` varchar(10) DEFAULT NULL,
  `Mattress_recycling_fee` varchar(10) DEFAULT NULL,
  `Battery_recycling_fee` varchar(10) DEFAULT NULL,
  `White_goods_disposal_tax` varchar(10) DEFAULT NULL,
  `Tyre_recycling_fee` varchar(10) DEFAULT NULL,
  `Additional_fee` varchar(10) DEFAULT NULL,
  `Total_price` decimal(11,2) DEFAULT NULL,
  `eBay_collected_tax_and_fees_included_in_total` varchar(5) DEFAULT NULL,
  `Payment_method` varchar(10) DEFAULT NULL,
  `Sale_date` date DEFAULT NULL,
  `Paid_on_date` varchar(10) DEFAULT NULL,
  `Post_by_date` varchar(10) DEFAULT NULL,
  `Minimum_estimated_delivery_date` varchar(10) DEFAULT NULL,
  `Maximum_estimated_delivery_date` varchar(10) DEFAULT NULL,
  `Dispatched_on_date` varchar(10) DEFAULT NULL,
  `Feedback_left` varchar(5) DEFAULT NULL,
  `Feedback_received` varchar(15) DEFAULT NULL,
  `My_item_note` varchar(500) DEFAULT NULL,
  `PayPal_transaction_ID` varchar(30) DEFAULT NULL,
  `Delivery_service` varchar(50) DEFAULT NULL,
  `Tracking_number` varchar(50) DEFAULT NULL,
  `Transaction_ID` varchar(30) DEFAULT NULL,
  `Variation_details` varchar(100) DEFAULT NULL,
  `Global_Shipping_Programme` varchar(5) DEFAULT NULL,
  `Global_Shipping_Reference_ID` varchar(30) DEFAULT NULL,
  `Click_and_Collect` varchar(5) DEFAULT NULL,
  `Click_and_Collect_Reference_Number` varchar(30) DEFAULT NULL,
  `eBay_Plus` varchar(5) DEFAULT NULL,
  `eBay_Fulfilment_Programme` varchar(5) DEFAULT NULL,
  `f_local` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_ref_no` varchar(50) DEFAULT NULL,
  `pur_ref_no_gd_hold` varchar(50) DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `tot_pur_amount` decimal(10,2) DEFAULT NULL,
  `item_note` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_audit_extra_in_ebay`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_audit_extra_in_ebay` (
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `f_active_owner` int(11) DEFAULT NULL,
  `account_types_owner` mediumtext DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `f_active_seller` int(11) DEFAULT NULL,
  `account_types_seller` mediumtext DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `f_authenticated` tinyint(4) DEFAULT NULL,
  `f_active_branch` tinyint(4) DEFAULT NULL,
  `ebay_order_no` varchar(30) DEFAULT NULL,
  `d_ebay_order` datetime DEFAULT NULL,
  `cust_name` varchar(250) DEFAULT NULL,
  `cust_phone` varchar(100) DEFAULT NULL,
  `cust_address` varchar(1500) DEFAULT NULL,
  `fk_id_product_branch` int(11) DEFAULT NULL,
  `sku_ebay` varchar(50) DEFAULT NULL,
  `product_name_local` varchar(255) DEFAULT NULL,
  `fk_id_product_type` int(11) DEFAULT NULL,
  `product_type_name` varchar(255) DEFAULT NULL,
  `iso_product_type` varchar(10) DEFAULT NULL,
  `f_active_product_type` tinyint(4) DEFAULT NULL,
  `f_active_product_branch` tinyint(4) DEFAULT NULL,
  `url_ebay_product` varchar(500) DEFAULT NULL,
  `qty_ordered` int(11) DEFAULT NULL,
  `sale_rate` decimal(10,2) DEFAULT NULL,
  `c_tot_amount` decimal(10,2) DEFAULT NULL,
  `c_tot_discounts` decimal(10,2) DEFAULT NULL,
  `c_tot_add` decimal(10,2) DEFAULT NULL,
  `c_grand_total` decimal(11,2) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `f_local` tinyint(1) DEFAULT NULL,
  `iso_vendor` varchar(10) DEFAULT NULL,
  `pur_ref_no` varchar(50) DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `pur_amount` decimal(20,2) DEFAULT NULL,
  `profit` decimal(21,2) DEFAULT NULL,
  `item_note` varchar(100) DEFAULT NULL,
  `sku_ebay_from_sale` varchar(100) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `carrier_name` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_audit_not_exist_in_ebay`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_audit_not_exist_in_ebay` (
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `sku_ebay` varchar(100) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `Sales_record_number` int(11) DEFAULT NULL,
  `Order_number` varchar(50) DEFAULT NULL,
  `Post_to_name` varchar(100) DEFAULT NULL,
  `Item_title` varchar(250) DEFAULT NULL,
  `Quantity` int(11) DEFAULT NULL,
  `Sold_for` decimal(10,2) DEFAULT NULL,
  `Total_price` decimal(11,2) DEFAULT NULL,
  `Sale_date` date DEFAULT NULL,
  `My_item_note` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_audit_pur_amazon`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_audit_pur_amazon` (
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `ebay_order_no` varchar(50) DEFAULT NULL,
  `d_ebay_order` date DEFAULT NULL,
  `sku_ebay` varchar(100) DEFAULT NULL,
  `Item_title` varchar(250) DEFAULT NULL,
  `qty_ebay` int(11) DEFAULT NULL,
  `sale_rate` decimal(10,2) DEFAULT NULL,
  `tot_sale_amount` decimal(11,2) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `cust_name_ebay` varchar(100) DEFAULT NULL,
  `cust_name_pur` varchar(200) DEFAULT NULL,
  `pur_order_no_ebay` varchar(50) DEFAULT NULL,
  `pur_order_no` varchar(50) DEFAULT NULL,
  `d_purchase_ebay` date DEFAULT NULL,
  `d_purchase_order` date DEFAULT NULL,
  `pur_rate_ebay` decimal(10,2) DEFAULT NULL,
  `qty_pur_ebay` int(11) DEFAULT NULL,
  `tot_pur_amount_ebay` decimal(10,2) DEFAULT NULL,
  `g_tot_pur_amount_ebay` decimal(32,2) DEFAULT NULL,
  `tot_pur_amount` decimal(10,2) DEFAULT NULL,
  `issue` int(2) DEFAULT NULL,
  `issue_descr` varchar(17) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_audit_pur_gd`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_audit_pur_gd` (
  `audit_descr` varchar(14) DEFAULT NULL,
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `sku_ebay` varchar(100) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `cust_name_ebay` varchar(100) DEFAULT NULL,
  `Post_to_phone` varchar(100) DEFAULT NULL,
  `cust_address1` varchar(100) DEFAULT NULL,
  `Post_to_address_2` varchar(100) DEFAULT NULL,
  `Post_to_city` varchar(100) DEFAULT NULL,
  `Post_to_county` varchar(100) DEFAULT NULL,
  `Post_to_postcode` varchar(100) DEFAULT NULL,
  `Post_to_country` varchar(100) DEFAULT NULL,
  `ebay_order_no` varchar(50) DEFAULT NULL,
  `Sales_record_number` int(11) DEFAULT NULL,
  `Item_number` varchar(30) DEFAULT NULL,
  `Item_title` varchar(250) DEFAULT NULL,
  `Custom_label` varchar(100) DEFAULT NULL,
  `qty_ebay` int(11) DEFAULT NULL,
  `sale_rate` decimal(10,2) DEFAULT NULL,
  `tot_sale_amount` decimal(11,2) DEFAULT NULL,
  `d_ebay_order` date DEFAULT NULL,
  `Paid_on_date` varchar(10) DEFAULT NULL,
  `Post_by_date` varchar(10) DEFAULT NULL,
  `Dispatched_on_date` varchar(10) DEFAULT NULL,
  `My_item_note` varchar(500) DEFAULT NULL,
  `Transaction_ID` varchar(30) DEFAULT NULL,
  `Variation_details` varchar(100) DEFAULT NULL,
  `f_local` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_order_no` varchar(50) DEFAULT NULL,
  `pur_order_no_hold_ebay` varchar(50) DEFAULT NULL,
  `d_purchase_ebay` date DEFAULT NULL,
  `qty_pur_ebay` int(11) DEFAULT NULL,
  `pur_rate_ebay` decimal(10,2) DEFAULT NULL,
  `tot_pur_amount_ebay` decimal(10,2) DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `d_purchase_order` date DEFAULT NULL,
  `cust_name_pur` varchar(200) DEFAULT NULL,
  `cust_address1_pur` varchar(200) DEFAULT NULL,
  `pur_rate` decimal(11,2) DEFAULT NULL,
  `tot_pur_amount` decimal(21,2) DEFAULT NULL,
  `tot_issues` int(4) DEFAULT NULL,
  `issue_descr` varchar(48) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_audit_pur_gd_ho_not_exist_in_ebay`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_audit_pur_gd_ho_not_exist_in_ebay` (
  `audit_descr` varchar(20) DEFAULT NULL,
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `order_no` varchar(50) DEFAULT NULL,
  `d_order` date DEFAULT NULL,
  `sku_gd` varchar(50) DEFAULT NULL,
  `e_hold_buy` varchar(5) DEFAULT NULL,
  `g_pur_price` decimal(11,2) DEFAULT NULL,
  `product_title` varchar(500) DEFAULT NULL,
  `qty` int(11) DEFAULT NULL,
  `price` decimal(11,2) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `trading_id` varchar(25) DEFAULT NULL,
  `tracking_number` varchar(25) DEFAULT NULL,
  `payer_email` varchar(200) DEFAULT NULL,
  `address1` varchar(200) DEFAULT NULL,
  `address2` varchar(200) DEFAULT NULL,
  `address3` varchar(200) DEFAULT NULL,
  `city` varchar(200) DEFAULT NULL,
  `county` varchar(200) DEFAULT NULL,
  `buyer_country` varchar(200) DEFAULT NULL,
  `recipient` varchar(200) DEFAULT NULL,
  `buyer_post_code` varchar(50) DEFAULT NULL,
  `buyer_email` varchar(200) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_audit_pur_gd_ho_not_exist_in_gd`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_audit_pur_gd_ho_not_exist_in_gd` (
  `audit_descr` varchar(18) DEFAULT NULL,
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `sku_ebay` varchar(100) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `Sales_record_number` int(11) DEFAULT NULL,
  `Order_number` varchar(50) DEFAULT NULL,
  `Buyer_username` varchar(25) DEFAULT NULL,
  `Buyer_name` varchar(150) DEFAULT NULL,
  `Buyer_email` varchar(150) DEFAULT NULL,
  `Buyer_note` varchar(100) DEFAULT NULL,
  `Buyer_address_1` varchar(250) DEFAULT NULL,
  `Buyer_address_2` varchar(250) DEFAULT NULL,
  `Buyer_city` varchar(150) DEFAULT NULL,
  `Buyer_county` varchar(150) DEFAULT NULL,
  `Buyer_postcode` varchar(50) DEFAULT NULL,
  `Buyer_country` varchar(150) DEFAULT NULL,
  `Buyer_tax_identifier_name` varchar(100) DEFAULT NULL,
  `Buyer_tax_identifier_value` varchar(100) DEFAULT NULL,
  `Post_to_name` varchar(100) DEFAULT NULL,
  `Post_to_phone` varchar(100) DEFAULT NULL,
  `Post_to_address_1` varchar(100) DEFAULT NULL,
  `Post_to_address_2` varchar(100) DEFAULT NULL,
  `Post_to_city` varchar(100) DEFAULT NULL,
  `Post_to_county` varchar(100) DEFAULT NULL,
  `Post_to_postcode` varchar(100) DEFAULT NULL,
  `Post_to_country` varchar(100) DEFAULT NULL,
  `Item_number` varchar(30) DEFAULT NULL,
  `Item_title` varchar(250) DEFAULT NULL,
  `Custom_label` varchar(100) DEFAULT NULL,
  `Sold_via_Promoted_listings` varchar(10) DEFAULT NULL,
  `Quantity` int(11) DEFAULT NULL,
  `Sold_for` decimal(10,2) DEFAULT NULL,
  `Postage_and_packaging` varchar(10) DEFAULT NULL,
  `Item_location` varchar(10) DEFAULT NULL,
  `Item_postcode` varchar(10) DEFAULT NULL,
  `Item_country` varchar(10) DEFAULT NULL,
  `Included_VAT_rate` varchar(10) DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Rate` varchar(10) DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Type` varchar(10) DEFAULT NULL,
  `eBay_reference_name` varchar(10) DEFAULT NULL,
  `eBay_reference_value` varchar(10) DEFAULT NULL,
  `Tax_status` varchar(10) DEFAULT NULL,
  `Seller_collected_tax` varchar(10) DEFAULT NULL,
  `eBay_collected_tax` varchar(10) DEFAULT NULL,
  `Electronic_waste_recycling_fee` varchar(10) DEFAULT NULL,
  `Mattress_recycling_fee` varchar(10) DEFAULT NULL,
  `Battery_recycling_fee` varchar(10) DEFAULT NULL,
  `White_goods_disposal_tax` varchar(10) DEFAULT NULL,
  `Tyre_recycling_fee` varchar(10) DEFAULT NULL,
  `Additional_fee` varchar(10) DEFAULT NULL,
  `Total_price` decimal(11,2) DEFAULT NULL,
  `eBay_collected_tax_and_fees_included_in_total` varchar(5) DEFAULT NULL,
  `Payment_method` varchar(10) DEFAULT NULL,
  `Sale_date` date DEFAULT NULL,
  `Paid_on_date` varchar(10) DEFAULT NULL,
  `Post_by_date` varchar(10) DEFAULT NULL,
  `Minimum_estimated_delivery_date` varchar(10) DEFAULT NULL,
  `Maximum_estimated_delivery_date` varchar(10) DEFAULT NULL,
  `Dispatched_on_date` varchar(10) DEFAULT NULL,
  `Feedback_left` varchar(5) DEFAULT NULL,
  `Feedback_received` varchar(15) DEFAULT NULL,
  `My_item_note` varchar(500) DEFAULT NULL,
  `PayPal_transaction_ID` varchar(30) DEFAULT NULL,
  `Delivery_service` varchar(50) DEFAULT NULL,
  `Tracking_number` varchar(50) DEFAULT NULL,
  `Transaction_ID` varchar(30) DEFAULT NULL,
  `Variation_details` varchar(100) DEFAULT NULL,
  `Global_Shipping_Programme` varchar(5) DEFAULT NULL,
  `Global_Shipping_Reference_ID` varchar(30) DEFAULT NULL,
  `Click_and_Collect` varchar(5) DEFAULT NULL,
  `Click_and_Collect_Reference_Number` varchar(30) DEFAULT NULL,
  `eBay_Plus` varchar(5) DEFAULT NULL,
  `eBay_Fulfilment_Programme` varchar(5) DEFAULT NULL,
  `f_local` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_ref_no` varchar(50) DEFAULT NULL,
  `pur_ref_no_gd_hold` varchar(50) DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `tot_pur_amount` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_audit_pur_gd_pb_found_ho_not_exist_in_gd`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_audit_pur_gd_pb_found_ho_not_exist_in_gd` (
  `audit_descr` varchar(28) DEFAULT NULL,
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `sku_ebay` varchar(100) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `Post_to_name` varchar(100) DEFAULT NULL,
  `Post_to_phone` varchar(100) DEFAULT NULL,
  `Post_to_address_1` varchar(100) DEFAULT NULL,
  `Post_to_address_2` varchar(100) DEFAULT NULL,
  `Post_to_city` varchar(100) DEFAULT NULL,
  `Post_to_county` varchar(100) DEFAULT NULL,
  `Post_to_postcode` varchar(100) DEFAULT NULL,
  `Post_to_country` varchar(100) DEFAULT NULL,
  `Order_number` varchar(50) DEFAULT NULL,
  `Sales_record_number` int(11) DEFAULT NULL,
  `Item_number` varchar(30) DEFAULT NULL,
  `Item_title` varchar(250) DEFAULT NULL,
  `Custom_label` varchar(100) DEFAULT NULL,
  `Quantity` int(11) DEFAULT NULL,
  `Sold_for` decimal(10,2) DEFAULT NULL,
  `Total_price` decimal(11,2) DEFAULT NULL,
  `Sale_date` date DEFAULT NULL,
  `Paid_on_date` varchar(10) DEFAULT NULL,
  `Post_by_date` varchar(10) DEFAULT NULL,
  `Dispatched_on_date` varchar(10) DEFAULT NULL,
  `My_item_note` varchar(500) DEFAULT NULL,
  `Transaction_ID` varchar(30) DEFAULT NULL,
  `Variation_details` varchar(100) DEFAULT NULL,
  `f_local` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_ref_no` varchar(50) DEFAULT NULL,
  `pur_ref_no_gd_hold` varchar(50) DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `tot_pur_amount` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_audit_pur_gd_pb_not_exist_in_ebay`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_audit_pur_gd_pb_not_exist_in_ebay` (
  `audit_descr` varchar(23) DEFAULT NULL,
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `order_no` varchar(50) DEFAULT NULL,
  `d_order` date DEFAULT NULL,
  `sku_gd` varchar(50) DEFAULT NULL,
  `e_hold_buy` varchar(5) DEFAULT NULL,
  `g_pur_price` decimal(11,2) DEFAULT NULL,
  `product_title` varchar(500) DEFAULT NULL,
  `qty` int(11) DEFAULT NULL,
  `price` decimal(11,2) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `trading_id` varchar(25) DEFAULT NULL,
  `tracking_number` varchar(25) DEFAULT NULL,
  `payer_email` varchar(200) DEFAULT NULL,
  `address1` varchar(200) DEFAULT NULL,
  `address2` varchar(200) DEFAULT NULL,
  `address3` varchar(200) DEFAULT NULL,
  `city` varchar(200) DEFAULT NULL,
  `county` varchar(200) DEFAULT NULL,
  `buyer_country` varchar(200) DEFAULT NULL,
  `recipient` varchar(200) DEFAULT NULL,
  `buyer_post_code` varchar(50) DEFAULT NULL,
  `buyer_email` varchar(200) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_audit_pur_gd_pb_not_exist_in_gd`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_audit_pur_gd_pb_not_exist_in_gd` (
  `audit_descr` varchar(21) DEFAULT NULL,
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `sku_ebay` varchar(100) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `Sales_record_number` int(11) DEFAULT NULL,
  `Order_number` varchar(50) DEFAULT NULL,
  `Buyer_username` varchar(25) DEFAULT NULL,
  `Buyer_name` varchar(150) DEFAULT NULL,
  `Buyer_email` varchar(150) DEFAULT NULL,
  `Buyer_note` varchar(100) DEFAULT NULL,
  `Buyer_address_1` varchar(250) DEFAULT NULL,
  `Buyer_address_2` varchar(250) DEFAULT NULL,
  `Buyer_city` varchar(150) DEFAULT NULL,
  `Buyer_county` varchar(150) DEFAULT NULL,
  `Buyer_postcode` varchar(50) DEFAULT NULL,
  `Buyer_country` varchar(150) DEFAULT NULL,
  `Buyer_tax_identifier_name` varchar(100) DEFAULT NULL,
  `Buyer_tax_identifier_value` varchar(100) DEFAULT NULL,
  `Post_to_name` varchar(100) DEFAULT NULL,
  `Post_to_phone` varchar(100) DEFAULT NULL,
  `Post_to_address_1` varchar(100) DEFAULT NULL,
  `Post_to_address_2` varchar(100) DEFAULT NULL,
  `Post_to_city` varchar(100) DEFAULT NULL,
  `Post_to_county` varchar(100) DEFAULT NULL,
  `Post_to_postcode` varchar(100) DEFAULT NULL,
  `Post_to_country` varchar(100) DEFAULT NULL,
  `Item_number` varchar(30) DEFAULT NULL,
  `Item_title` varchar(250) DEFAULT NULL,
  `Custom_label` varchar(100) DEFAULT NULL,
  `Sold_via_Promoted_listings` varchar(10) DEFAULT NULL,
  `Quantity` int(11) DEFAULT NULL,
  `Sold_for` decimal(10,2) DEFAULT NULL,
  `Postage_and_packaging` varchar(10) DEFAULT NULL,
  `Item_location` varchar(10) DEFAULT NULL,
  `Item_postcode` varchar(10) DEFAULT NULL,
  `Item_country` varchar(10) DEFAULT NULL,
  `Included_VAT_rate` varchar(10) DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Rate` varchar(10) DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Type` varchar(10) DEFAULT NULL,
  `eBay_reference_name` varchar(10) DEFAULT NULL,
  `eBay_reference_value` varchar(10) DEFAULT NULL,
  `Tax_status` varchar(10) DEFAULT NULL,
  `Seller_collected_tax` varchar(10) DEFAULT NULL,
  `eBay_collected_tax` varchar(10) DEFAULT NULL,
  `Electronic_waste_recycling_fee` varchar(10) DEFAULT NULL,
  `Mattress_recycling_fee` varchar(10) DEFAULT NULL,
  `Battery_recycling_fee` varchar(10) DEFAULT NULL,
  `White_goods_disposal_tax` varchar(10) DEFAULT NULL,
  `Tyre_recycling_fee` varchar(10) DEFAULT NULL,
  `Additional_fee` varchar(10) DEFAULT NULL,
  `Total_price` decimal(11,2) DEFAULT NULL,
  `eBay_collected_tax_and_fees_included_in_total` varchar(5) DEFAULT NULL,
  `Payment_method` varchar(10) DEFAULT NULL,
  `Sale_date` date DEFAULT NULL,
  `Paid_on_date` varchar(10) DEFAULT NULL,
  `Post_by_date` varchar(10) DEFAULT NULL,
  `Minimum_estimated_delivery_date` varchar(10) DEFAULT NULL,
  `Maximum_estimated_delivery_date` varchar(10) DEFAULT NULL,
  `Dispatched_on_date` varchar(10) DEFAULT NULL,
  `Feedback_left` varchar(5) DEFAULT NULL,
  `Feedback_received` varchar(15) DEFAULT NULL,
  `My_item_note` varchar(500) DEFAULT NULL,
  `PayPal_transaction_ID` varchar(30) DEFAULT NULL,
  `Delivery_service` varchar(50) DEFAULT NULL,
  `Tracking_number` varchar(50) DEFAULT NULL,
  `Transaction_ID` varchar(30) DEFAULT NULL,
  `Variation_details` varchar(100) DEFAULT NULL,
  `Global_Shipping_Programme` varchar(5) DEFAULT NULL,
  `Global_Shipping_Reference_ID` varchar(30) DEFAULT NULL,
  `Click_and_Collect` varchar(5) DEFAULT NULL,
  `Click_and_Collect_Reference_Number` varchar(30) DEFAULT NULL,
  `eBay_Plus` varchar(5) DEFAULT NULL,
  `eBay_Fulfilment_Programme` varchar(5) DEFAULT NULL,
  `f_local` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_ref_no` varchar(50) DEFAULT NULL,
  `pur_ref_no_gd_hold` varchar(50) DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `tot_pur_amount` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_audit_sale_exist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_audit_sale_exist` (
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `sku_ebay` varchar(100) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `Sales_record_number` int(11) DEFAULT NULL,
  `Order_number` varchar(50) DEFAULT NULL,
  `cust_name_orig` varchar(100) DEFAULT NULL,
  `cust_name` varchar(250) DEFAULT NULL,
  `Post_to_address_1_orig` varchar(100) DEFAULT NULL,
  `Post_to_address_2_orig` varchar(100) DEFAULT NULL,
  `Post_to_city_orig` varchar(100) DEFAULT NULL,
  `Post_to_country_orig` varchar(100) DEFAULT NULL,
  `cust_address` varchar(1500) DEFAULT NULL,
  `cust_phone_orig` varchar(100) DEFAULT NULL,
  `cust_phone` varchar(100) DEFAULT NULL,
  `Item_title_orig` varchar(250) DEFAULT NULL,
  `qty_ordered_orig` int(11) DEFAULT NULL,
  `qty_ordered` int(11) DEFAULT NULL,
  `sale_rate_orig` decimal(10,2) DEFAULT NULL,
  `sale_rate` decimal(10,2) DEFAULT NULL,
  `c_grand_total_orig` decimal(11,2) DEFAULT NULL,
  `c_grand_total` decimal(11,2) DEFAULT NULL,
  `d_ebay_order_orig` date DEFAULT NULL,
  `d_ebay_order` datetime DEFAULT NULL,
  `item_note_orig` varchar(500) DEFAULT NULL,
  `item_note` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_cat_account_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_cat_account_types` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `account_type_name` varchar(500) DEFAULT NULL,
  `iso_account_type` varchar(10) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_cat_country`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_cat_country` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `country_code` int(11) DEFAULT NULL,
  `country_name` varchar(200) DEFAULT NULL,
  `iso_code` varchar(10) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_cat_doc_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_cat_doc_types` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `doc_type_name` varchar(200) DEFAULT NULL,
  `iso_doc_type` varchar(10) DEFAULT NULL,
  `f_active` tinyint(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_cat_mobile_network`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_cat_mobile_network` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `country_code` int(11) DEFAULT NULL,
  `country_name` varchar(200) DEFAULT NULL,
  `iso_code` varchar(10) DEFAULT NULL,
  `f_active_country` int(11) DEFAULT NULL,
  `network_name` varchar(100) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_cat_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_cat_modules` (
  `id` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active` tinyint(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_cat_product_trends`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_cat_product_trends` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `product_trend_name` varchar(255) DEFAULT NULL,
  `iso_product_trend` varchar(10) DEFAULT NULL,
  `f_active` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_cat_product_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_cat_product_type` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `product_type_name` varchar(255) DEFAULT NULL,
  `iso_product_type` varchar(10) DEFAULT NULL,
  `f_active` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_cat_rights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_cat_rights` (
  `id` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `right_name` varchar(100) DEFAULT NULL,
  `iso_right` varchar(10) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_ebay`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_ebay` (
  `id` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `Sales_record_number` int(11) DEFAULT NULL,
  `Order_number` varchar(50) DEFAULT NULL,
  `Buyer_username` varchar(25) DEFAULT NULL,
  `Buyer_name` varchar(150) DEFAULT NULL,
  `Buyer_email` varchar(150) DEFAULT NULL,
  `Buyer_note` varchar(10) DEFAULT NULL,
  `Buyer_address_1` varchar(250) DEFAULT NULL,
  `Buyer_address_2` varchar(250) DEFAULT NULL,
  `Buyer_city` varchar(150) DEFAULT NULL,
  `Buyer_county` varchar(150) DEFAULT NULL,
  `Buyer_postcode` varchar(50) DEFAULT NULL,
  `Buyer_country` varchar(150) DEFAULT NULL,
  `Buyer_tax_identifier_name` varchar(100) DEFAULT NULL,
  `Buyer_tax_identifier_value` varchar(100) DEFAULT NULL,
  `Post_to_name` varchar(100) DEFAULT NULL,
  `Post_to_phone` varchar(100) DEFAULT NULL,
  `Post_to_address_1` varchar(100) DEFAULT NULL,
  `Post_to_address_2` varchar(100) DEFAULT NULL,
  `Post_to_city` varchar(100) DEFAULT NULL,
  `Post_to_county` varchar(100) DEFAULT NULL,
  `Post_to_postcode` varchar(100) DEFAULT NULL,
  `Post_to_country` varchar(100) DEFAULT NULL,
  `Item_number` varchar(30) DEFAULT NULL,
  `Item_title` varchar(250) DEFAULT NULL,
  `Custom_label` varchar(50) DEFAULT NULL,
  `Sold_via_Promoted_listings` varchar(5) DEFAULT NULL,
  `Quantity` varchar(4) DEFAULT NULL,
  `Sold_for` varchar(10) DEFAULT NULL,
  `Postage_and_packaging` varchar(10) DEFAULT NULL,
  `Item_location` varchar(10) DEFAULT NULL,
  `Item_postcode` varchar(10) DEFAULT NULL,
  `Item_country` varchar(10) DEFAULT NULL,
  `Included_VAT_rate` varchar(10) DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Rate` varchar(10) DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Type` varchar(10) DEFAULT NULL,
  `eBay_reference_name` varchar(10) DEFAULT NULL,
  `eBay_reference_value` varchar(10) DEFAULT NULL,
  `Tax_status` varchar(10) DEFAULT NULL,
  `Seller_collected_tax` varchar(10) DEFAULT NULL,
  `eBay_collected_tax` varchar(10) DEFAULT NULL,
  `Electronic_waste_recycling_fee` varchar(10) DEFAULT NULL,
  `Mattress_recycling_fee` varchar(10) DEFAULT NULL,
  `Battery_recycling_fee` varchar(10) DEFAULT NULL,
  `White_goods_disposal_tax` varchar(10) DEFAULT NULL,
  `Tyre_recycling_fee` varchar(10) DEFAULT NULL,
  `Additional_fee` varchar(10) DEFAULT NULL,
  `Total_price` varchar(25) DEFAULT NULL,
  `eBay_collected_tax_and_fees_included_in_total` varchar(5) DEFAULT NULL,
  `Payment_method` varchar(10) DEFAULT NULL,
  `Sale_date` varchar(10) DEFAULT NULL,
  `Paid_on_date` varchar(10) DEFAULT NULL,
  `Post_by_date` varchar(10) DEFAULT NULL,
  `Minimum_estimated_delivery_date` varchar(10) DEFAULT NULL,
  `Maximum_estimated_delivery_date` varchar(10) DEFAULT NULL,
  `Dispatched_on_date` varchar(10) DEFAULT NULL,
  `Feedback_left` varchar(5) DEFAULT NULL,
  `Feedback_received` varchar(5) DEFAULT NULL,
  `My_item_note` varchar(500) DEFAULT NULL,
  `PayPal_transaction_ID` varchar(30) DEFAULT NULL,
  `Delivery_service` varchar(50) DEFAULT NULL,
  `Tracking_number` varchar(20) DEFAULT NULL,
  `Transaction_ID` varchar(30) DEFAULT NULL,
  `Variation_details` varchar(100) DEFAULT NULL,
  `Global_Shipping_Programme` varchar(5) DEFAULT NULL,
  `Global_Shipping_Reference_ID` varchar(30) DEFAULT NULL,
  `Click_and_Collect` varchar(5) DEFAULT NULL,
  `Click_and_Collect_Reference_Number` varchar(30) DEFAULT NULL,
  `eBay_Plus` varchar(5) DEFAULT NULL,
  `eBay_Fulfilment_Programme` varchar(5) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_ebay_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_ebay_audit` (
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `sku_ebay` varchar(100) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `Sales_record_number` int(11) DEFAULT NULL,
  `Order_number` varchar(50) DEFAULT NULL,
  `Buyer_username` varchar(25) DEFAULT NULL,
  `Buyer_name` varchar(150) DEFAULT NULL,
  `Buyer_email` varchar(150) DEFAULT NULL,
  `Buyer_note` varchar(100) DEFAULT NULL,
  `Buyer_address_1` varchar(250) DEFAULT NULL,
  `Buyer_address_2` varchar(250) DEFAULT NULL,
  `Buyer_city` varchar(150) DEFAULT NULL,
  `Buyer_county` varchar(150) DEFAULT NULL,
  `Buyer_postcode` varchar(50) DEFAULT NULL,
  `Buyer_country` varchar(150) DEFAULT NULL,
  `Buyer_tax_identifier_name` varchar(100) DEFAULT NULL,
  `Buyer_tax_identifier_value` varchar(100) DEFAULT NULL,
  `Post_to_name` varchar(100) DEFAULT NULL,
  `Post_to_phone` varchar(100) DEFAULT NULL,
  `Post_to_address_1` varchar(100) DEFAULT NULL,
  `Post_to_address_2` varchar(100) DEFAULT NULL,
  `Post_to_city` varchar(100) DEFAULT NULL,
  `Post_to_county` varchar(100) DEFAULT NULL,
  `Post_to_postcode` varchar(100) DEFAULT NULL,
  `Post_to_country` varchar(100) DEFAULT NULL,
  `Item_number` varchar(30) DEFAULT NULL,
  `Item_title` varchar(250) DEFAULT NULL,
  `Custom_label` varchar(100) DEFAULT NULL,
  `Sold_via_Promoted_listings` varchar(10) DEFAULT NULL,
  `Quantity` int(11) DEFAULT NULL,
  `Sold_for` decimal(10,2) DEFAULT NULL,
  `Postage_and_packaging` varchar(10) DEFAULT NULL,
  `Item_location` varchar(10) DEFAULT NULL,
  `Item_postcode` varchar(10) DEFAULT NULL,
  `Item_country` varchar(10) DEFAULT NULL,
  `Included_VAT_rate` varchar(10) DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Rate` varchar(10) DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Type` varchar(10) DEFAULT NULL,
  `eBay_reference_name` varchar(10) DEFAULT NULL,
  `eBay_reference_value` varchar(10) DEFAULT NULL,
  `Tax_status` varchar(10) DEFAULT NULL,
  `Seller_collected_tax` varchar(10) DEFAULT NULL,
  `eBay_collected_tax` varchar(10) DEFAULT NULL,
  `Electronic_waste_recycling_fee` varchar(10) DEFAULT NULL,
  `Mattress_recycling_fee` varchar(10) DEFAULT NULL,
  `Battery_recycling_fee` varchar(10) DEFAULT NULL,
  `White_goods_disposal_tax` varchar(10) DEFAULT NULL,
  `Tyre_recycling_fee` varchar(10) DEFAULT NULL,
  `Additional_fee` varchar(10) DEFAULT NULL,
  `Total_price` decimal(11,2) DEFAULT NULL,
  `eBay_collected_tax_and_fees_included_in_total` varchar(5) DEFAULT NULL,
  `Payment_method` varchar(10) DEFAULT NULL,
  `Sale_date` date DEFAULT NULL,
  `Paid_on_date` varchar(10) DEFAULT NULL,
  `Post_by_date` varchar(10) DEFAULT NULL,
  `Minimum_estimated_delivery_date` varchar(10) DEFAULT NULL,
  `Maximum_estimated_delivery_date` varchar(10) DEFAULT NULL,
  `Dispatched_on_date` varchar(10) DEFAULT NULL,
  `Feedback_left` varchar(5) DEFAULT NULL,
  `Feedback_received` varchar(15) DEFAULT NULL,
  `My_item_note` varchar(500) DEFAULT NULL,
  `PayPal_transaction_ID` varchar(30) DEFAULT NULL,
  `Delivery_service` varchar(50) DEFAULT NULL,
  `Tracking_number` varchar(50) DEFAULT NULL,
  `Transaction_ID` varchar(30) DEFAULT NULL,
  `Variation_details` varchar(100) DEFAULT NULL,
  `Global_Shipping_Programme` varchar(5) DEFAULT NULL,
  `Global_Shipping_Reference_ID` varchar(30) DEFAULT NULL,
  `Click_and_Collect` varchar(5) DEFAULT NULL,
  `Click_and_Collect_Reference_Number` varchar(30) DEFAULT NULL,
  `eBay_Plus` varchar(5) DEFAULT NULL,
  `eBay_Fulfilment_Programme` varchar(5) DEFAULT NULL,
  `f_local` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_ref_no` varchar(50) DEFAULT NULL,
  `pur_ref_no_gd_hold` varchar(50) DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `tot_pur_amount` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_ebay_audit_gd_only`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_ebay_audit_gd_only` (
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `sku_ebay` varchar(100) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `Sales_record_number` int(11) DEFAULT NULL,
  `Order_number` varchar(50) DEFAULT NULL,
  `Buyer_username` varchar(25) DEFAULT NULL,
  `Buyer_name` varchar(150) DEFAULT NULL,
  `Buyer_email` varchar(150) DEFAULT NULL,
  `Buyer_note` varchar(100) DEFAULT NULL,
  `Buyer_address_1` varchar(250) DEFAULT NULL,
  `Buyer_address_2` varchar(250) DEFAULT NULL,
  `Buyer_city` varchar(150) DEFAULT NULL,
  `Buyer_county` varchar(150) DEFAULT NULL,
  `Buyer_postcode` varchar(50) DEFAULT NULL,
  `Buyer_country` varchar(150) DEFAULT NULL,
  `Buyer_tax_identifier_name` varchar(100) DEFAULT NULL,
  `Buyer_tax_identifier_value` varchar(100) DEFAULT NULL,
  `Post_to_name` varchar(100) DEFAULT NULL,
  `Post_to_phone` varchar(100) DEFAULT NULL,
  `Post_to_address_1` varchar(100) DEFAULT NULL,
  `Post_to_address_2` varchar(100) DEFAULT NULL,
  `Post_to_city` varchar(100) DEFAULT NULL,
  `Post_to_county` varchar(100) DEFAULT NULL,
  `Post_to_postcode` varchar(100) DEFAULT NULL,
  `Post_to_country` varchar(100) DEFAULT NULL,
  `Item_number` varchar(30) DEFAULT NULL,
  `Item_title` varchar(250) DEFAULT NULL,
  `Custom_label` varchar(100) DEFAULT NULL,
  `Sold_via_Promoted_listings` varchar(10) DEFAULT NULL,
  `Quantity` int(11) DEFAULT NULL,
  `Sold_for` decimal(10,2) DEFAULT NULL,
  `Postage_and_packaging` varchar(10) DEFAULT NULL,
  `Item_location` varchar(10) DEFAULT NULL,
  `Item_postcode` varchar(10) DEFAULT NULL,
  `Item_country` varchar(10) DEFAULT NULL,
  `Included_VAT_rate` varchar(10) DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Rate` varchar(10) DEFAULT NULL,
  `eBay_Collect_And_Remit_Tax_Type` varchar(10) DEFAULT NULL,
  `eBay_reference_name` varchar(10) DEFAULT NULL,
  `eBay_reference_value` varchar(10) DEFAULT NULL,
  `Tax_status` varchar(10) DEFAULT NULL,
  `Seller_collected_tax` varchar(10) DEFAULT NULL,
  `eBay_collected_tax` varchar(10) DEFAULT NULL,
  `Electronic_waste_recycling_fee` varchar(10) DEFAULT NULL,
  `Mattress_recycling_fee` varchar(10) DEFAULT NULL,
  `Battery_recycling_fee` varchar(10) DEFAULT NULL,
  `White_goods_disposal_tax` varchar(10) DEFAULT NULL,
  `Tyre_recycling_fee` varchar(10) DEFAULT NULL,
  `Additional_fee` varchar(10) DEFAULT NULL,
  `Total_price` decimal(11,2) DEFAULT NULL,
  `eBay_collected_tax_and_fees_included_in_total` varchar(5) DEFAULT NULL,
  `Payment_method` varchar(10) DEFAULT NULL,
  `Sale_date` date DEFAULT NULL,
  `Paid_on_date` varchar(10) DEFAULT NULL,
  `Post_by_date` varchar(10) DEFAULT NULL,
  `Minimum_estimated_delivery_date` varchar(10) DEFAULT NULL,
  `Maximum_estimated_delivery_date` varchar(10) DEFAULT NULL,
  `Dispatched_on_date` varchar(10) DEFAULT NULL,
  `Feedback_left` varchar(5) DEFAULT NULL,
  `Feedback_received` varchar(15) DEFAULT NULL,
  `My_item_note` varchar(500) DEFAULT NULL,
  `PayPal_transaction_ID` varchar(30) DEFAULT NULL,
  `Delivery_service` varchar(50) DEFAULT NULL,
  `Tracking_number` varchar(50) DEFAULT NULL,
  `Transaction_ID` varchar(30) DEFAULT NULL,
  `Variation_details` varchar(100) DEFAULT NULL,
  `Global_Shipping_Programme` varchar(5) DEFAULT NULL,
  `Global_Shipping_Reference_ID` varchar(30) DEFAULT NULL,
  `Click_and_Collect` varchar(5) DEFAULT NULL,
  `Click_and_Collect_Reference_Number` varchar(30) DEFAULT NULL,
  `eBay_Plus` varchar(5) DEFAULT NULL,
  `eBay_Fulfilment_Programme` varchar(5) DEFAULT NULL,
  `f_local` int(11) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_ref_no` varchar(50) DEFAULT NULL,
  `pur_ref_no_gd_hold` varchar(50) DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `tot_pur_amount` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_gd_data_update`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_gd_data_update` (
  `qry` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_months`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_months` (
  `monthno` int(2) DEFAULT NULL,
  `month` varchar(9) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_pur_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_pur_audit` (
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `order_no` varchar(50) DEFAULT NULL,
  `d_order` date DEFAULT NULL,
  `pur_amount` decimal(10,2) DEFAULT NULL,
  `cust_name` varchar(200) DEFAULT NULL,
  `cust_address` varchar(1000) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_pur_audit_gd`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_pur_audit_gd` (
  `id` int(11) DEFAULT NULL,
  `fk_id_audit_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `order_no` varchar(50) DEFAULT NULL,
  `d_order` date DEFAULT NULL,
  `sku_gd` varchar(50) DEFAULT NULL,
  `e_hold_buy` varchar(5) DEFAULT NULL,
  `g_pur_price` decimal(11,2) DEFAULT NULL,
  `product_title` varchar(500) DEFAULT NULL,
  `qty` int(11) DEFAULT NULL,
  `price` decimal(11,2) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `trading_id` varchar(25) DEFAULT NULL,
  `tracking_number` varchar(25) DEFAULT NULL,
  `payer_email` varchar(200) DEFAULT NULL,
  `address1` varchar(200) DEFAULT NULL,
  `address2` varchar(200) DEFAULT NULL,
  `address3` varchar(200) DEFAULT NULL,
  `city` varchar(200) DEFAULT NULL,
  `county` varchar(200) DEFAULT NULL,
  `buyer_country` varchar(200) DEFAULT NULL,
  `recipient` varchar(200) DEFAULT NULL,
  `buyer_post_code` varchar(50) DEFAULT NULL,
  `buyer_email` varchar(200) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_sale_diff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_sale_diff` (
  `id` int(11) DEFAULT NULL,
  `fk_id_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `f_sale_loaded` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `order_id` varchar(40) DEFAULT NULL,
  `d_sale` date DEFAULT NULL,
  `item_title` varchar(1000) DEFAULT NULL,
  `no_of_items` int(11) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `customer_name` varchar(250) DEFAULT NULL,
  `cust_address` varchar(800) DEFAULT NULL,
  `item_note` varchar(150) DEFAULT NULL,
  `item_note_orig` varchar(500) DEFAULT NULL,
  `order_id_buy_in_sale` varchar(50) DEFAULT NULL,
  `reason_by_va` varchar(250) DEFAULT NULL,
  `id_buy` int(11) DEFAULT NULL,
  `order_id_buy` varchar(40) DEFAULT NULL,
  `d_buy` date DEFAULT NULL,
  `item_title_buy` varchar(1000) DEFAULT NULL,
  `no_of_items_buy` int(11) DEFAULT NULL,
  `price_buy` decimal(10,2) DEFAULT NULL,
  `cust_name_buy` varchar(250) DEFAULT NULL,
  `cust_address_buy` varchar(800) DEFAULT NULL,
  `profit` decimal(11,2) DEFAULT NULL,
  `diff_descr` varchar(14) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_sale_summary_diff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_sale_summary_diff` (
  `fk_id_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `f_sale_loaded` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `no_of_items` decimal(32,0) DEFAULT NULL,
  `price` decimal(32,2) DEFAULT NULL,
  `customer_name` varchar(250) DEFAULT NULL,
  `cust_address` varchar(800) DEFAULT NULL,
  `no_of_items_buy` decimal(32,0) DEFAULT NULL,
  `price_buy` decimal(32,2) DEFAULT NULL,
  `cust_name_buy` varchar(250) DEFAULT NULL,
  `cust_address_buy` varchar(800) DEFAULT NULL,
  `profit` decimal(33,2) DEFAULT NULL,
  `diff_descr` varchar(14) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_account`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_account` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `account_code` varchar(10) DEFAULT NULL,
  `first_name` varchar(200) DEFAULT NULL,
  `middle_name` varchar(200) DEFAULT NULL,
  `sur_name` varchar(200) DEFAULT NULL,
  `full_name` varchar(401) DEFAULT NULL,
  `name_descriptive` longtext DEFAULT NULL,
  `title` varchar(20) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL,
  `account_types` mediumtext DEFAULT NULL,
  `iso_account_types` mediumtext DEFAULT NULL,
  `account_addresses` mediumtext DEFAULT NULL,
  `login_id` varchar(150) DEFAULT NULL,
  `pass` varchar(50) DEFAULT NULL,
  `f_login` tinyint(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_account_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_account_addresses` (
  `id` int(11) DEFAULT NULL,
  `fk_id_account` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `account_code` varchar(10) DEFAULT NULL,
  `account_types` mediumtext DEFAULT NULL,
  `first_name` varchar(200) DEFAULT NULL,
  `middle_name` varchar(200) DEFAULT NULL,
  `sur_name` varchar(200) DEFAULT NULL,
  `full_name` varchar(401) DEFAULT NULL,
  `name_descriptive` longtext DEFAULT NULL,
  `title` varchar(20) DEFAULT NULL,
  `f_active_account` int(11) DEFAULT NULL,
  `account_address` text DEFAULT NULL,
  `f_international` tinyint(4) DEFAULT NULL,
  `line1` varchar(500) DEFAULT NULL,
  `line2` varchar(500) DEFAULT NULL,
  `line3` varchar(500) DEFAULT NULL,
  `line4` varchar(500) DEFAULT NULL,
  `line5` varchar(500) DEFAULT NULL,
  `post_code` varchar(50) DEFAULT NULL,
  `counti` varchar(500) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `country_code` int(11) DEFAULT NULL,
  `country_name` varchar(200) DEFAULT NULL,
  `iso_code` varchar(10) DEFAULT NULL,
  `f_active_country` int(11) DEFAULT NULL,
  `l_address_type` enum('H','O') DEFAULT NULL,
  `l_address_type_descr` varchar(6) DEFAULT NULL,
  `f_used_for_all_entities` tinyint(4) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_account_addresses_1`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_account_addresses_1` (
  `id` int(11) DEFAULT NULL,
  `fk_id_account` int(11) DEFAULT NULL,
  `account_address` text DEFAULT NULL,
  `f_international` tinyint(4) DEFAULT NULL,
  `line1` varchar(500) DEFAULT NULL,
  `line2` varchar(500) DEFAULT NULL,
  `line3` varchar(500) DEFAULT NULL,
  `line4` varchar(500) DEFAULT NULL,
  `line5` varchar(500) DEFAULT NULL,
  `post_code` varchar(50) DEFAULT NULL,
  `counti` varchar(500) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `country_code` int(11) DEFAULT NULL,
  `country_name` varchar(200) DEFAULT NULL,
  `iso_code` varchar(10) DEFAULT NULL,
  `f_active_country` int(11) DEFAULT NULL,
  `l_address_type` enum('H','O') DEFAULT NULL,
  `l_address_type_descr` varchar(6) DEFAULT NULL,
  `f_used_for_all_entities` tinyint(4) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_account_doc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_account_doc` (
  `id` int(11) DEFAULT NULL,
  `fk_id_account` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `account_code` varchar(10) DEFAULT NULL,
  `first_name` varchar(200) DEFAULT NULL,
  `name_descriptive` longtext DEFAULT NULL,
  `middle_name` varchar(200) DEFAULT NULL,
  `sur_name` varchar(200) DEFAULT NULL,
  `full_name` varchar(401) DEFAULT NULL,
  `title` varchar(20) DEFAULT NULL,
  `f_active_account` int(11) DEFAULT NULL,
  `fk_id_doc_type` int(11) DEFAULT NULL,
  `doc_type_name` varchar(200) DEFAULT NULL,
  `iso_doc_type` varchar(10) DEFAULT NULL,
  `f_active_doc_type` tinyint(4) DEFAULT NULL,
  `doc_no` varchar(100) DEFAULT NULL,
  `f_validate` tinyint(4) DEFAULT NULL,
  `d_expire` date DEFAULT NULL,
  `file_name` varchar(100) DEFAULT NULL,
  `f_active` tinyint(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_account_in_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_account_in_types` (
  `id` int(11) DEFAULT NULL,
  `fk_id_account` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `account_code` varchar(10) DEFAULT NULL,
  `account_types` mediumtext DEFAULT NULL,
  `first_name` varchar(200) DEFAULT NULL,
  `middle_name` varchar(200) DEFAULT NULL,
  `sur_name` varchar(200) DEFAULT NULL,
  `full_name` varchar(401) DEFAULT NULL,
  `name_descriptive` longtext DEFAULT NULL,
  `title` varchar(20) DEFAULT NULL,
  `f_active_account` int(11) DEFAULT NULL,
  `fk_id_account_type` int(11) DEFAULT NULL,
  `account_type_name` varchar(500) DEFAULT NULL,
  `iso_account_type` varchar(10) DEFAULT NULL,
  `f_active_type` int(11) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_account_in_types_1`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_account_in_types_1` (
  `id` int(11) DEFAULT NULL,
  `fk_id_account` int(11) DEFAULT NULL,
  `fk_id_account_type` int(11) DEFAULT NULL,
  `account_type_name` varchar(500) DEFAULT NULL,
  `iso_account_type` varchar(10) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL,
  `f_active_type` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_account_mobiles_1`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_account_mobiles_1` (
  `id` int(11) DEFAULT NULL,
  `fk_id_account` int(11) DEFAULT NULL,
  `l_office_home` enum('O','H') DEFAULT NULL,
  `l_office_home_descr` varchar(6) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `country_code` int(11) DEFAULT NULL,
  `country_name` varchar(200) DEFAULT NULL,
  `iso_code_country` varchar(10) DEFAULT NULL,
  `f_active_country` int(11) DEFAULT NULL,
  `mobile_no` varchar(20) DEFAULT NULL,
  `fk_id_mobile_network` int(11) DEFAULT NULL,
  `network_name` varchar(100) DEFAULT NULL,
  `f_active_mob_network` int(11) DEFAULT NULL,
  `l_recharge_contract` enum('R','C') DEFAULT NULL,
  `l_recharge_contract_descr` varchar(8) DEFAULT NULL,
  `simno` varchar(100) DEFAULT NULL,
  `fk_id_mobile_device` int(11) DEFAULT NULL,
  `mobile_name` varchar(200) DEFAULT NULL,
  `mobile_code` varchar(50) DEFAULT NULL,
  `tot_slots` int(11) DEFAULT NULL,
  `f_active_mob_device` int(11) DEFAULT NULL,
  `slotno` int(11) DEFAULT NULL,
  `mobile_info` varchar(289) DEFAULT NULL,
  `fk_id_address` int(11) DEFAULT NULL,
  `account_address` text DEFAULT NULL,
  `f_international` tinyint(4) DEFAULT NULL,
  `line1` varchar(500) DEFAULT NULL,
  `line2` varchar(500) DEFAULT NULL,
  `line3` varchar(500) DEFAULT NULL,
  `line4` varchar(500) DEFAULT NULL,
  `line5` varchar(500) DEFAULT NULL,
  `post_code` varchar(50) DEFAULT NULL,
  `counti` varchar(500) DEFAULT NULL,
  `fk_id_country_addr` int(11) DEFAULT NULL,
  `country_code_addr` int(11) DEFAULT NULL,
  `country_name_addr` varchar(200) DEFAULT NULL,
  `iso_code_country_addr` varchar(10) DEFAULT NULL,
  `f_active_country_addr` int(11) DEFAULT NULL,
  `l_address_type` enum('H','O') DEFAULT NULL,
  `l_address_type_descr` varchar(6) DEFAULT NULL,
  `f_used_for_all_entities` tinyint(4) DEFAULT NULL,
  `f_active_address` int(11) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_account_rights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_account_rights` (
  `id` int(11) DEFAULT NULL,
  `fk_id_account` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `account_types` mediumtext DEFAULT NULL,
  `login_id` varchar(150) DEFAULT NULL,
  `f_login` tinyint(4) DEFAULT NULL,
  `fk_id_right` int(11) DEFAULT NULL,
  `right_name` varchar(100) DEFAULT NULL,
  `iso_right` varchar(10) DEFAULT NULL,
  `f_active_right` int(11) DEFAULT NULL,
  `e_status` enum('GRANTED','REVOKED','NA') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_amazon_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_amazon_orders` (
  `id` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `order_date` varchar(15) DEFAULT NULL,
  `order_id` varchar(25) DEFAULT NULL,
  `acc_group` varchar(50) DEFAULT NULL,
  `po_number` varchar(25) DEFAULT NULL,
  `qty` varchar(5) DEFAULT NULL,
  `currency` varchar(15) DEFAULT NULL,
  `order_sub_total` varchar(8) DEFAULT NULL,
  `shipping` varchar(15) DEFAULT NULL,
  `promotion` varchar(15) DEFAULT NULL,
  `order_vat` varchar(15) DEFAULT NULL,
  `order_net_total` varchar(8) DEFAULT NULL,
  `order_status` varchar(50) DEFAULT NULL,
  `approver` varchar(50) DEFAULT NULL,
  `user` varchar(60) DEFAULT NULL,
  `email` varchar(80) DEFAULT NULL,
  `user_vat_no_region` varchar(15) DEFAULT NULL,
  `user_vat_reg_no` varchar(15) DEFAULT NULL,
  `inv_status` varchar(15) DEFAULT NULL,
  `inv_tot_amt` varchar(15) DEFAULT NULL,
  `inv_due_amt` varchar(15) DEFAULT NULL,
  `inv_issue_date` varchar(15) DEFAULT NULL,
  `inv_due_date` varchar(15) DEFAULT NULL,
  `vat_inv_no` varchar(15) DEFAULT NULL,
  `payment_date` varchar(15) DEFAULT NULL,
  `payment_amt` varchar(15) DEFAULT NULL,
  `payment_inst_type` varchar(15) DEFAULT NULL,
  `payment_identfier` varchar(15) DEFAULT NULL,
  `amazon_product_category` varchar(100) DEFAULT NULL,
  `ASIN` varchar(15) DEFAULT NULL,
  `Item_title` varchar(250) DEFAULT NULL,
  `UNSPSC` varchar(15) DEFAULT NULL,
  `segment` varchar(200) DEFAULT NULL,
  `family` varchar(100) DEFAULT NULL,
  `class` varchar(50) DEFAULT NULL,
  `commodity` varchar(50) DEFAULT NULL,
  `brand_code` varchar(15) DEFAULT NULL,
  `brand_name` varchar(40) DEFAULT NULL,
  `manufacturer` varchar(15) DEFAULT NULL,
  `national_stock_no` varchar(15) DEFAULT NULL,
  `item_model_no` varchar(15) DEFAULT NULL,
  `part_no` varchar(15) DEFAULT NULL,
  `prod_cond` varchar(15) DEFAULT NULL,
  `comp_compliance` varchar(15) DEFAULT NULL,
  `listed_ppu` varchar(15) DEFAULT NULL,
  `pur_ppu` varchar(15) DEFAULT NULL,
  `item_qty` varchar(15) DEFAULT NULL,
  `item_sub_total` varchar(15) DEFAULT NULL,
  `item_shipping` varchar(15) DEFAULT NULL,
  `item_promotion` varchar(15) DEFAULT NULL,
  `item_vat` varchar(15) DEFAULT NULL,
  `item_net_total` varchar(15) DEFAULT NULL,
  `line_item_id` varchar(15) DEFAULT NULL,
  `disc_prog` varchar(15) DEFAULT NULL,
  `disc_applied_amt` varchar(15) DEFAULT NULL,
  `disc_applied_per` varchar(15) DEFAULT NULL,
  `item_sib_total_vat_rate` varchar(15) DEFAULT NULL,
  `item_gift_wrap` varchar(15) DEFAULT NULL,
  `item_sub_total_sum` varchar(15) DEFAULT NULL,
  `gl_code` varchar(15) DEFAULT NULL,
  `deprtment` varchar(15) DEFAULT NULL,
  `cost_centre` varchar(15) DEFAULT NULL,
  `project_code` varchar(15) DEFAULT NULL,
  `location` varchar(15) DEFAULT NULL,
  `customer_field` varchar(15) DEFAULT NULL,
  `seller_name` varchar(200) DEFAULT NULL,
  `seller_cred` varchar(30) DEFAULT NULL,
  `seller_vat_no_country` varchar(30) DEFAULT NULL,
  `seller_vat_reg_no` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_audit_main`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_audit_main` (
  `id` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `f_active_owner` int(11) DEFAULT NULL,
  `account_types_owner` mediumtext DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `f_active_seller` int(11) DEFAULT NULL,
  `account_types_seller` mediumtext DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `f_authenticated` tinyint(4) DEFAULT NULL,
  `f_active_branch` tinyint(4) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `d_yr_mon` date DEFAULT NULL,
  `e_audit_status` enum('COMPLETED','PENDING','SKIP','SALE-LOADED') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_branch_params_all`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_branch_params_all` (
  `fk_id_branch` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `f_active_branch` tinyint(4) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `fk_id_prams` int(11) DEFAULT NULL,
  `param_name` varchar(50) DEFAULT NULL,
  `l_ent_branch` enum('E','B','A') DEFAULT NULL,
  `f_lov` int(11) DEFAULT NULL,
  `f_active_param` int(11) DEFAULT NULL,
  `id` int(11) DEFAULT NULL,
  `params_value` varchar(500) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_branch_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_branch_reminders` (
  `id` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `f_active_owner` int(11) DEFAULT NULL,
  `account_types_owner` mediumtext DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `f_active_seller` int(11) DEFAULT NULL,
  `account_types_seller` mediumtext DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `f_authenticated` tinyint(4) DEFAULT NULL,
  `f_active_branch` tinyint(4) DEFAULT NULL,
  `month` int(11) DEFAULT NULL,
  `year` int(11) DEFAULT NULL,
  `d_entry` date DEFAULT NULL,
  `fk_id_rem_type` int(11) DEFAULT NULL,
  `iso_reminder` varchar(20) DEFAULT NULL,
  `reminder_descr` varchar(200) DEFAULT NULL,
  `e_status` enum('PENDING','RESOLVED') DEFAULT NULL,
  `reminder_text` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_branches` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `fk_id_marketplace` int(11) DEFAULT NULL,
  `marketplace_short` varchar(50) DEFAULT NULL,
  `marketplace_descr` varchar(200) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `account_code_owner` varchar(10) DEFAULT NULL,
  `account_types_owner` mediumtext DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `name_descriptive_owner` longtext DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `f_active_owner` int(11) DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `account_code_seller` varchar(10) DEFAULT NULL,
  `account_types_seller` mediumtext DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `name_descriptive_seller` longtext DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `f_active_seller` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_profile_url` varchar(500) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_address` int(11) DEFAULT NULL,
  `f_international` tinyint(4) DEFAULT NULL,
  `line1` varchar(500) DEFAULT NULL,
  `line2` varchar(500) DEFAULT NULL,
  `line3` varchar(500) DEFAULT NULL,
  `line4` varchar(500) DEFAULT NULL,
  `line5` varchar(500) DEFAULT NULL,
  `post_code` varchar(50) DEFAULT NULL,
  `counti` varchar(500) DEFAULT NULL,
  `fk_id_country` int(11) DEFAULT NULL,
  `country_code` int(11) DEFAULT NULL,
  `country_name` varchar(200) DEFAULT NULL,
  `iso_code_country` varchar(10) DEFAULT NULL,
  `f_active_country` int(11) DEFAULT NULL,
  `l_address_type` enum('H','O') DEFAULT NULL,
  `l_address_type_descr` varchar(6) DEFAULT NULL,
  `f_used_for_all_entities` tinyint(4) DEFAULT NULL,
  `f_active_address` int(11) DEFAULT NULL,
  `mobile_info` varchar(289) DEFAULT NULL,
  `fk_id_mobile` int(11) DEFAULT NULL,
  `l_office_home_mob` enum('O','H') DEFAULT NULL,
  `l_office_home_descr_mob` varchar(6) DEFAULT NULL,
  `fk_id_country_mob` int(11) DEFAULT NULL,
  `country_code_mob` int(11) DEFAULT NULL,
  `country_name_mob` varchar(200) DEFAULT NULL,
  `iso_code_country_mob` varchar(10) DEFAULT NULL,
  `f_active_country_mob` int(11) DEFAULT NULL,
  `mobile_no` varchar(20) DEFAULT NULL,
  `fk_id_mobile_network` int(11) DEFAULT NULL,
  `network_name` varchar(100) DEFAULT NULL,
  `f_active_mob_network` int(11) DEFAULT NULL,
  `l_recharge_contract` enum('R','C') DEFAULT NULL,
  `simno` varchar(100) DEFAULT NULL,
  `fk_id_mobile_device` int(11) DEFAULT NULL,
  `mobile_name` varchar(200) DEFAULT NULL,
  `mobile_code` varchar(50) DEFAULT NULL,
  `tot_slots` int(11) DEFAULT NULL,
  `shop_mobile_address` text DEFAULT NULL,
  `fk_id_address_mob` int(11) DEFAULT NULL,
  `f_authenticated` tinyint(4) DEFAULT NULL,
  `f_active` tinyint(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_buy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_buy` (
  `id` int(11) DEFAULT NULL,
  `fk_id_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `f_sale_loaded` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `iso_vendor` varchar(10) DEFAULT NULL,
  `f_local_vendor` tinyint(1) DEFAULT NULL,
  `f_active_vendor` tinyint(1) DEFAULT NULL,
  `order_id` varchar(40) DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `item_title` varchar(1000) DEFAULT NULL,
  `d_buy` date DEFAULT NULL,
  `no_of_items` int(11) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `customer_name` varchar(250) DEFAULT NULL,
  `cust_address` varchar(800) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_buy_summary`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_buy_summary` (
  `fk_id_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `f_sale_loaded` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `iso_vendor` varchar(10) DEFAULT NULL,
  `f_local_vendor` tinyint(1) DEFAULT NULL,
  `f_active_vendor` tinyint(1) DEFAULT NULL,
  `customer_name` varchar(250) DEFAULT NULL,
  `cust_address` varchar(800) DEFAULT NULL,
  `no_of_items` decimal(32,0) DEFAULT NULL,
  `price` decimal(32,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_buy_vendors_grouped`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_buy_vendors_grouped` (
  `fk_id_main` int(11) DEFAULT NULL,
  `iso_vendors` mediumtext DEFAULT NULL,
  `vendors` mediumtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_car_svc_pkg_all`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_car_svc_pkg_all` (
  `id_virtual` varchar(44) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `carrier_name` varchar(200) DEFAULT NULL,
  `f_active_carrier` int(11) DEFAULT NULL,
  `fk_id_car_package` int(11) DEFAULT NULL,
  `package_name` varchar(150) DEFAULT NULL,
  `weight` decimal(10,2) DEFAULT NULL,
  `dim_length` decimal(10,2) DEFAULT NULL,
  `dim_width` decimal(10,2) DEFAULT NULL,
  `dim_height` decimal(10,2) DEFAULT NULL,
  `f_active_pkg` tinyint(4) DEFAULT NULL,
  `fk_id_car_service` int(11) DEFAULT NULL,
  `service_name` varchar(150) DEFAULT NULL,
  `f_active_svc` tinyint(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_car_svc_pkg_all_adv`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_car_svc_pkg_all_adv` (
  `id_virtual` varchar(44) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `carrier_name` varchar(200) DEFAULT NULL,
  `f_active_carrier` int(11) DEFAULT NULL,
  `fk_id_car_package` int(11) DEFAULT NULL,
  `package_name` varchar(150) DEFAULT NULL,
  `weight` decimal(10,2) DEFAULT NULL,
  `dim_length` decimal(10,2) DEFAULT NULL,
  `dim_width` decimal(10,2) DEFAULT NULL,
  `dim_height` decimal(10,2) DEFAULT NULL,
  `f_active_pkg` tinyint(4) DEFAULT NULL,
  `fk_id_car_service` int(11) DEFAULT NULL,
  `service_name` varchar(150) DEFAULT NULL,
  `f_active_svc` tinyint(4) DEFAULT NULL,
  `id` int(11) DEFAULT NULL,
  `pkg_svc_name` varchar(150) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_carrier_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_carrier_packages` (
  `id` int(11) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `carrier_name` varchar(200) DEFAULT NULL,
  `f_active_carrier` int(11) DEFAULT NULL,
  `package_name` varchar(150) DEFAULT NULL,
  `weight` decimal(10,2) DEFAULT NULL,
  `dim_length` decimal(10,2) DEFAULT NULL,
  `dim_width` decimal(10,2) DEFAULT NULL,
  `dim_height` decimal(10,2) DEFAULT NULL,
  `f_active` tinyint(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_carrier_pkg_svc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_carrier_pkg_svc` (
  `id` int(11) DEFAULT NULL,
  `pkg_svc_name` varchar(150) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `carrier_name` varchar(200) DEFAULT NULL,
  `f_active_carrier` int(11) DEFAULT NULL,
  `fk_id_car_package` int(11) DEFAULT NULL,
  `package_name` varchar(150) DEFAULT NULL,
  `weight` decimal(10,2) DEFAULT NULL,
  `dim_length` decimal(10,2) DEFAULT NULL,
  `dim_width` decimal(10,2) DEFAULT NULL,
  `dim_height` decimal(10,2) DEFAULT NULL,
  `f_active_pkg` tinyint(4) DEFAULT NULL,
  `fk_id_car_service` int(11) DEFAULT NULL,
  `service_name` varchar(150) DEFAULT NULL,
  `f_Active_svc` tinyint(4) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_carrier_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_carrier_services` (
  `id` int(11) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `carrier_name` varchar(200) DEFAULT NULL,
  `f_active_carrier` int(11) DEFAULT NULL,
  `service_name` varchar(150) DEFAULT NULL,
  `f_active` tinyint(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_controller_rights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_controller_rights` (
  `id` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `fk_id_right` int(11) DEFAULT NULL,
  `right_name` varchar(100) DEFAULT NULL,
  `iso_right` varchar(10) DEFAULT NULL,
  `f_active_right` int(11) DEFAULT NULL,
  `fk_id_controller` int(11) DEFAULT NULL,
  `controller_name` varchar(250) DEFAULT NULL,
  `iso_controller` varchar(50) DEFAULT NULL,
  `f_active_controller` smallint(6) DEFAULT NULL,
  `f_read` smallint(6) DEFAULT NULL,
  `f_insert` smallint(6) DEFAULT NULL,
  `f_update` smallint(6) DEFAULT NULL,
  `f_delete` int(11) DEFAULT NULL,
  `f_all` smallint(6) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_ent_params_all`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_ent_params_all` (
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `fk_id_prams` int(11) DEFAULT NULL,
  `param_name` varchar(50) DEFAULT NULL,
  `l_ent_branch` enum('E','B','A') DEFAULT NULL,
  `f_lov` int(11) DEFAULT NULL,
  `f_active_param` int(11) DEFAULT NULL,
  `id` int(11) DEFAULT NULL,
  `params_value` varchar(500) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_enterprise`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_enterprise` (
  `id` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active` tinyint(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_mobile_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_mobile_devices` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `mobile_name` varchar(200) DEFAULT NULL,
  `mobile_code` varchar(50) DEFAULT NULL,
  `tot_slots` int(11) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_packings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_packings` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `packing_name` varchar(100) DEFAULT NULL,
  `packing_rate` decimal(10,2) DEFAULT NULL,
  `f_active` tinyint(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_product_vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_product_vendors` (
  `id` int(11) DEFAULT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `product_name_main` varchar(255) DEFAULT NULL,
  `product_main_description` varchar(255) DEFAULT NULL,
  `f_active_product_main` tinyint(1) DEFAULT NULL,
  `fk_id_product_type_main` int(11) DEFAULT NULL,
  `product_type_name_main` varchar(255) DEFAULT NULL,
  `iso_product_type_main` varchar(10) DEFAULT NULL,
  `f_active_product_type_main` tinyint(1) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `f_local` tinyint(1) DEFAULT NULL,
  `f_active_vendor` tinyint(1) DEFAULT NULL,
  `product_name_vendor` varchar(255) DEFAULT NULL,
  `ean` varchar(20) DEFAULT NULL,
  `fk_id_product_type_vendor` int(11) DEFAULT NULL,
  `product_type_name_vendor` varchar(255) DEFAULT NULL,
  `iso_product_type_vendor` varchar(10) DEFAULT NULL,
  `f_active_product_type_vendor` tinyint(1) DEFAULT NULL,
  `fk_id_product_trend_vendor` int(11) DEFAULT NULL,
  `product_trend_name_vendor` varchar(255) DEFAULT NULL,
  `iso_product_trend_vendor` varchar(10) DEFAULT NULL,
  `f_active_product_trend_vendor` tinyint(1) DEFAULT NULL,
  `fk_id_product_vendor_self` int(11) DEFAULT NULL,
  `f_family` int(1) DEFAULT NULL,
  `variant` varchar(70) DEFAULT NULL,
  `upc` varchar(50) DEFAULT NULL,
  `opc` varchar(50) DEFAULT NULL,
  `d_created` date DEFAULT NULL,
  `fk_id_country_make` int(11) DEFAULT NULL,
  `country_code` int(11) DEFAULT NULL,
  `country_name` varchar(200) DEFAULT NULL,
  `iso_code_country` varchar(10) DEFAULT NULL,
  `f_active_country` int(11) DEFAULT NULL,
  `weight` varchar(50) DEFAULT NULL,
  `dimensions` varchar(15) DEFAULT NULL,
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `pkg_svc_name` varchar(150) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `carrier_name` varchar(200) DEFAULT NULL,
  `f_active_courier` int(11) DEFAULT NULL,
  `fk_id_carrier_package` int(11) DEFAULT NULL,
  `package_name` varchar(150) DEFAULT NULL,
  `f_active_package` tinyint(4) DEFAULT NULL,
  `fk_id_carrier_service` int(11) DEFAULT NULL,
  `service_name` varchar(150) DEFAULT NULL,
  `f_active_service` tinyint(4) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL,
  `fk_id_packing` int(11) DEFAULT NULL,
  `packing_name` varchar(100) DEFAULT NULL,
  `packing_rate` decimal(10,2) DEFAULT NULL,
  `f_active_packing` tinyint(4) DEFAULT NULL,
  `url_ebay` varchar(500) DEFAULT NULL,
  `ebay_fee` decimal(10,2) DEFAULT NULL,
  `sale_price_raw` decimal(14,2) DEFAULT NULL,
  `vat` decimal(15,2) DEFAULT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `list_price` decimal(10,2) DEFAULT NULL,
  `stock` int(11) DEFAULT NULL,
  `f_out_of_stock` tinyint(4) DEFAULT NULL,
  `out_of_stock` varchar(12) DEFAULT NULL,
  `min_level` int(11) DEFAULT NULL,
  `f_alert_min_level` tinyint(1) DEFAULT NULL,
  `href_product` varchar(255) DEFAULT NULL,
  `sku_vendor` varchar(50) DEFAULT NULL,
  `f_active` tinyint(1) DEFAULT NULL,
  `f_parent` tinyint(4) DEFAULT NULL,
  `f_id_parent` int(11) DEFAULT NULL,
  `fk_id_account_added_by` int(11) DEFAULT NULL,
  `fk_id_account_updated_by` int(11) DEFAULT NULL,
  `carrier` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_products_branch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_products_branch` (
  `id` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `f_active_owner` int(11) DEFAULT NULL,
  `account_types_owner` mediumtext DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `f_active_seller` int(11) DEFAULT NULL,
  `account_types_seller` mediumtext DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `f_authenticated` tinyint(4) DEFAULT NULL,
  `f_active_branch` tinyint(4) DEFAULT NULL,
  `sku_ebay` varchar(25) DEFAULT NULL,
  `product_name_local` varchar(255) DEFAULT NULL,
  `fk_id_product_type` int(11) DEFAULT NULL,
  `product_type_name` varchar(255) DEFAULT NULL,
  `iso_product_type` varchar(10) DEFAULT NULL,
  `f_active_product_type` tinyint(1) DEFAULT NULL,
  `sale_price_ebay` decimal(10,2) DEFAULT NULL,
  `url_ebay_product` varchar(500) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `f_local` tinyint(1) DEFAULT NULL,
  `f_active_vendor` tinyint(1) DEFAULT NULL,
  `pur_price` decimal(10,2) DEFAULT NULL,
  `sku_vendor` varchar(25) DEFAULT NULL,
  `f_active` tinyint(1) DEFAULT NULL,
  `url_vendor_product` varchar(500) DEFAULT NULL,
  `asin_amazon` varchar(100) DEFAULT NULL,
  `ref_vendor_product` varchar(100) DEFAULT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL,
  `product_name_main` varchar(255) DEFAULT NULL,
  `product_description_main` varchar(255) DEFAULT NULL,
  `iso_product_type_main` varchar(10) DEFAULT NULL,
  `f_active_product_type_main` tinyint(1) DEFAULT NULL,
  `f_active_product_main` tinyint(1) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `carrier_name` varchar(200) DEFAULT NULL,
  `f_active_carrier` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_products_branch_1`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_products_branch_1` (
  `id` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `sku_ebay` varchar(25) DEFAULT NULL,
  `product_name_local` varchar(255) DEFAULT NULL,
  `fk_id_product_type` int(11) DEFAULT NULL,
  `product_type_name` varchar(255) DEFAULT NULL,
  `iso_product_type` varchar(10) DEFAULT NULL,
  `f_active_product_type` tinyint(1) DEFAULT NULL,
  `sale_price_ebay` decimal(10,2) DEFAULT NULL,
  `url_ebay_product` varchar(500) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_price` decimal(10,2) DEFAULT NULL,
  `sku_vendor` varchar(25) DEFAULT NULL,
  `f_active` tinyint(1) DEFAULT NULL,
  `url_vendor_product` varchar(500) DEFAULT NULL,
  `asin_amazon` varchar(100) DEFAULT NULL,
  `ref_vendor_product` varchar(100) DEFAULT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_products_main`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_products_main` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `f_active` tinyint(1) DEFAULT NULL,
  `fk_id_product_type` int(11) DEFAULT NULL,
  `product_type_name` varchar(255) DEFAULT NULL,
  `iso_product_type` varchar(10) DEFAULT NULL,
  `f_active_product_type` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_products_main_all`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_products_main_all` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `f_active` tinyint(1) DEFAULT NULL,
  `fk_id_product_type` int(11) DEFAULT NULL,
  `product_type_name` varchar(255) DEFAULT NULL,
  `iso_product_type` varchar(10) DEFAULT NULL,
  `f_active_product_type` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_products_shop_not_listed`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_products_shop_not_listed` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `sku_vendor` varchar(50) DEFAULT NULL,
  `product_name_local` varchar(255) DEFAULT NULL,
  `fk_id_product_type` int(11) DEFAULT NULL,
  `product_type_name` varchar(255) DEFAULT NULL,
  `iso_product_type` varchar(10) DEFAULT NULL,
  `f_active_product_type` tinyint(1) DEFAULT NULL,
  `sale_price_raw` decimal(14,2) DEFAULT NULL,
  `vat` decimal(15,2) DEFAULT NULL,
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `pkg_svc_name` varchar(150) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `carrier_name` varchar(200) DEFAULT NULL,
  `f_active_courier` int(11) DEFAULT NULL,
  `fk_id_carrier_package` int(11) DEFAULT NULL,
  `package_name` varchar(150) DEFAULT NULL,
  `car_pkg_rate` decimal(10,2) DEFAULT NULL,
  `f_active_package` tinyint(4) DEFAULT NULL,
  `fk_id_packing` int(11) DEFAULT NULL,
  `packing_name` varchar(100) DEFAULT NULL,
  `packing_rate` decimal(10,2) DEFAULT NULL,
  `f_active_packing` tinyint(4) DEFAULT NULL,
  `sale_price_ebay` decimal(10,2) DEFAULT NULL,
  `url_ebay_product` varchar(500) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_price` decimal(10,2) DEFAULT NULL,
  `f_active` tinyint(1) DEFAULT NULL,
  `url_vendor_product` varchar(255) DEFAULT NULL,
  `asin_amazon` varchar(50) DEFAULT NULL,
  `ref_vendor_product` binary(0) DEFAULT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_products_shop_not_listed_summary`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_products_shop_not_listed_summary` (
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_marketplace` int(11) DEFAULT NULL,
  `marketplace_short` varchar(50) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `total_products` bigint(21) DEFAULT NULL,
  `not_used` bigint(21) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_products_va_and_local`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_products_va_and_local` (
  `id` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `f_active_owner` int(11) DEFAULT NULL,
  `account_types_owner` mediumtext DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `f_active_seller` int(11) DEFAULT NULL,
  `account_types_seller` mediumtext DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `f_authenticated` tinyint(4) DEFAULT NULL,
  `f_active_branch` tinyint(4) DEFAULT NULL,
  `sku_ebay` varchar(50) DEFAULT NULL,
  `product_name_local` varchar(255) DEFAULT NULL,
  `fk_id_product_type` int(11) DEFAULT NULL,
  `product_type_name` varchar(255) DEFAULT NULL,
  `iso_product_type` varchar(10) DEFAULT NULL,
  `f_active_product_type` tinyint(4) DEFAULT NULL,
  `sale_price_ebay` decimal(10,2) DEFAULT NULL,
  `url_ebay_product` varchar(500) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `f_local` tinyint(4) DEFAULT NULL,
  `f_active_vendor` tinyint(4) DEFAULT NULL,
  `pur_price` decimal(10,2) DEFAULT NULL,
  `sku_vendor` varchar(50) DEFAULT NULL,
  `f_active` tinyint(4) DEFAULT NULL,
  `url_vendor_product` varchar(500) DEFAULT NULL,
  `asin_amazon` varchar(100) DEFAULT NULL,
  `ref_vendor_product` varchar(100) DEFAULT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL,
  `product_name_main` varchar(255) DEFAULT NULL,
  `product_description_main` varchar(255) DEFAULT NULL,
  `iso_product_type_main` varchar(10) DEFAULT NULL,
  `f_active_product_type_main` tinyint(4) DEFAULT NULL,
  `f_active_product_main` tinyint(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_products_va_and_local_1`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_products_va_and_local_1` (
  `id` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `sku_ebay` varchar(50) DEFAULT NULL,
  `product_name_local` varchar(255) DEFAULT NULL,
  `fk_id_product_type` int(11) DEFAULT NULL,
  `product_type_name` varchar(255) DEFAULT NULL,
  `iso_product_type` varchar(10) DEFAULT NULL,
  `f_active_product_type` tinyint(4) DEFAULT NULL,
  `sale_price_raw` decimal(14,2) DEFAULT NULL,
  `vat` decimal(15,2) DEFAULT NULL,
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `pkg_svc_name` varchar(150) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `carrier_name` varchar(200) DEFAULT NULL,
  `f_active_courier` int(11) DEFAULT NULL,
  `fk_id_carrier_package` int(11) DEFAULT NULL,
  `package_name` varchar(150) DEFAULT NULL,
  `car_pkg_rate` decimal(10,2) DEFAULT NULL,
  `f_active_package` tinyint(4) DEFAULT NULL,
  `fk_id_packing` int(11) DEFAULT NULL,
  `packing_name` varchar(100) DEFAULT NULL,
  `packing_rate` decimal(10,2) DEFAULT NULL,
  `f_active_packing` tinyint(4) DEFAULT NULL,
  `sale_price_ebay` decimal(10,2) DEFAULT NULL,
  `url_ebay_product` varchar(500) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `pur_price` decimal(10,2) DEFAULT NULL,
  `sku_vendor` varchar(50) DEFAULT NULL,
  `f_active` tinyint(4) DEFAULT NULL,
  `url_vendor_product` varchar(500) DEFAULT NULL,
  `asin_amazon` varchar(100) DEFAULT NULL,
  `ref_vendor_product` varchar(100) DEFAULT NULL,
  `fk_id_product_main` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_role_rights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_role_rights` (
  `id` int(11) DEFAULT NULL,
  `fk_id_role` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `role_name` varchar(500) DEFAULT NULL,
  `iso_role` varchar(10) DEFAULT NULL,
  `f_active_role` int(11) DEFAULT NULL,
  `fk_id_right` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `right_name` varchar(100) DEFAULT NULL,
  `iso_right` varchar(10) DEFAULT NULL,
  `f_active_right` int(11) DEFAULT NULL,
  `f_active` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_sale`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_sale` (
  `id` int(11) DEFAULT NULL,
  `fk_id_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `f_sale_loaded` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `order_id` varchar(40) DEFAULT NULL,
  `d_sale` date DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `item_title` varchar(1000) DEFAULT NULL,
  `no_of_items` int(11) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `customer_name` varchar(250) DEFAULT NULL,
  `cust_address` varchar(800) DEFAULT NULL,
  `item_note` varchar(150) DEFAULT NULL,
  `item_note_orig` varchar(500) DEFAULT NULL,
  `order_id_buy_in_sale` varchar(50) DEFAULT NULL,
  `reason_by_va` varchar(250) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_sale_buy_main`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_sale_buy_main` (
  `id` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `account_code_owner` varchar(10) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `f_active_owner` int(11) DEFAULT NULL,
  `account_types_owner` mediumtext DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `account_code_seller` varchar(10) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `f_active_seller` int(11) DEFAULT NULL,
  `account_types_seller` mediumtext DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `f_authenticated` tinyint(4) DEFAULT NULL,
  `f_active_branch` tinyint(4) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `d_yr_mon` date DEFAULT NULL,
  `f_sale_loaded` int(11) DEFAULT NULL,
  `f_buy_loaded` int(1) DEFAULT NULL,
  `vendors` mediumtext DEFAULT NULL,
  `iso_vendors` mediumtext DEFAULT NULL,
  `d_sale_summary` datetime DEFAULT NULL,
  `f_summary_generated` tinyint(4) DEFAULT NULL,
  `tot_sale` decimal(10,2) DEFAULT NULL,
  `sale_fee` decimal(10,2) DEFAULT NULL,
  `net_sale` decimal(10,2) DEFAULT NULL,
  `net_buy` decimal(10,2) DEFAULT NULL,
  `tot_profit` decimal(10,2) DEFAULT NULL,
  `tot_expenses` decimal(10,2) DEFAULT NULL,
  `expenses_descr` varchar(200) DEFAULT NULL,
  `net_profit` decimal(10,2) DEFAULT NULL,
  `seller_per` decimal(10,2) DEFAULT NULL,
  `seller_profit_in_pund` decimal(10,2) DEFAULT NULL,
  `exchange_rate` decimal(10,2) DEFAULT NULL,
  `seller_profit_in_pkr` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_sale_order_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_sale_order_reminders` (
  `id` int(11) DEFAULT NULL,
  `fk_id_t_sale_orders` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `f_active_owner` int(11) DEFAULT NULL,
  `account_types_owner` mediumtext DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `f_active_seller` int(11) DEFAULT NULL,
  `account_types_seller` mediumtext DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `f_authenticated` tinyint(4) DEFAULT NULL,
  `f_active_branch` tinyint(4) DEFAULT NULL,
  `ebay_order_no` varchar(30) DEFAULT NULL,
  `d_ebay_order` datetime DEFAULT NULL,
  `cust_name` varchar(250) DEFAULT NULL,
  `cust_phone` varchar(100) DEFAULT NULL,
  `cust_address` varchar(1500) DEFAULT NULL,
  `fk_id_product_branch` int(11) DEFAULT NULL,
  `sku_ebay` varchar(50) DEFAULT NULL,
  `product_name_local` varchar(255) DEFAULT NULL,
  `fk_id_product_type` int(11) DEFAULT NULL,
  `product_type_name` varchar(255) DEFAULT NULL,
  `iso_product_type` varchar(10) DEFAULT NULL,
  `f_active_product_type` tinyint(4) DEFAULT NULL,
  `f_active_product_branch` tinyint(4) DEFAULT NULL,
  `url_ebay_product` varchar(500) DEFAULT NULL,
  `qty_ordered` int(11) DEFAULT NULL,
  `sale_rate` decimal(10,2) DEFAULT NULL,
  `c_tot_amount` decimal(10,2) DEFAULT NULL,
  `c_tot_discounts` decimal(10,2) DEFAULT NULL,
  `c_tot_add` decimal(10,2) DEFAULT NULL,
  `c_grand_total` decimal(11,2) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `f_local` tinyint(1) DEFAULT NULL,
  `iso_vendor` varchar(10) DEFAULT NULL,
  `pur_ref_no` varchar(50) DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `pur_amount` decimal(20,2) DEFAULT NULL,
  `profit` decimal(21,2) DEFAULT NULL,
  `item_note` varchar(100) DEFAULT NULL,
  `sku_ebay_from_sale` varchar(100) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `carrier_name` varchar(200) DEFAULT NULL,
  `fk_id_rem_type` int(11) DEFAULT NULL,
  `iso_reminder` varchar(20) DEFAULT NULL,
  `reminder_descr` varchar(200) DEFAULT NULL,
  `va_remarks` varchar(500) DEFAULT NULL,
  `d_entered` datetime DEFAULT NULL,
  `fk_id_t_users` int(11) DEFAULT NULL,
  `e_status` enum('PENDING','RESOLVED') DEFAULT NULL,
  `admin_remarks` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_sale_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_sale_orders` (
  `id` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `f_active_owner` int(11) DEFAULT NULL,
  `account_types_owner` mediumtext DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `f_active_seller` int(11) DEFAULT NULL,
  `account_types_seller` mediumtext DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `f_authenticated` tinyint(4) DEFAULT NULL,
  `f_active_branch` tinyint(4) DEFAULT NULL,
  `ebay_order_no` varchar(30) DEFAULT NULL,
  `d_ebay_order` datetime DEFAULT NULL,
  `d_ebay_order_str` varchar(10) DEFAULT NULL,
  `cust_name` varchar(250) DEFAULT NULL,
  `cust_phone` varchar(100) DEFAULT NULL,
  `cust_address` varchar(1500) DEFAULT NULL,
  `fk_id_product_branch` int(11) DEFAULT NULL,
  `sku_ebay` varchar(50) DEFAULT NULL,
  `product_name_local` varchar(255) DEFAULT NULL,
  `fk_id_product_type` int(11) DEFAULT NULL,
  `product_type_name` varchar(255) DEFAULT NULL,
  `iso_product_type` varchar(10) DEFAULT NULL,
  `f_active_product_type` tinyint(4) DEFAULT NULL,
  `f_active_product_branch` tinyint(4) DEFAULT NULL,
  `url_ebay_product` varchar(500) DEFAULT NULL,
  `qty_ordered` int(11) DEFAULT NULL,
  `sale_rate` decimal(10,2) DEFAULT NULL,
  `c_tot_amount` decimal(10,2) DEFAULT NULL,
  `c_tot_discounts` decimal(10,2) DEFAULT NULL,
  `c_tot_add` decimal(10,2) DEFAULT NULL,
  `c_grand_total` decimal(11,2) DEFAULT NULL,
  `fk_id_vendor` int(11) DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `f_local` tinyint(1) DEFAULT NULL,
  `iso_vendor` varchar(10) DEFAULT NULL,
  `pur_ref_no` varchar(50) DEFAULT NULL,
  `d_purchase` date DEFAULT NULL,
  `qty_pur` int(11) DEFAULT NULL,
  `pur_rate` decimal(10,2) DEFAULT NULL,
  `pur_rate_cur` decimal(10,2) DEFAULT NULL,
  `pur_rate_raw` decimal(10,2) DEFAULT NULL,
  `vat` decimal(15,2) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `carrier_name` varchar(200) DEFAULT NULL,
  `f_active_courier` int(11) DEFAULT NULL,
  `fk_id_carrier_package` int(11) DEFAULT NULL,
  `package_name` varchar(150) DEFAULT NULL,
  `car_pkg_rate` decimal(10,2) DEFAULT NULL,
  `f_active_package` tinyint(4) DEFAULT NULL,
  `fk_id_packing` int(11) DEFAULT NULL,
  `packing_name` varchar(100) DEFAULT NULL,
  `packing_rate` decimal(10,2) DEFAULT NULL,
  `f_active_packing` tinyint(4) DEFAULT NULL,
  `pur_amount` decimal(20,2) DEFAULT NULL,
  `profit` decimal(21,2) DEFAULT NULL,
  `item_note` varchar(100) DEFAULT NULL,
  `sku_ebay_from_sale` varchar(100) DEFAULT NULL,
  `reason_by_va` varchar(250) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_sale_summary`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_sale_summary` (
  `fk_id_main` int(11) DEFAULT NULL,
  `mon` int(11) DEFAULT NULL,
  `yr` int(11) DEFAULT NULL,
  `f_sale_loaded` int(11) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_branch` varchar(100) DEFAULT NULL,
  `shop_no` varchar(11) DEFAULT NULL,
  `shop_name` varchar(200) DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `fk_id_seller` int(11) DEFAULT NULL,
  `title_seller` varchar(20) DEFAULT NULL,
  `first_name_seller` varchar(200) DEFAULT NULL,
  `middle_name_seller` varchar(200) DEFAULT NULL,
  `sur_name_seller` varchar(200) DEFAULT NULL,
  `full_name_seller` varchar(401) DEFAULT NULL,
  `fk_id_owner` int(11) DEFAULT NULL,
  `title_owner` varchar(20) DEFAULT NULL,
  `first_name_owner` varchar(200) DEFAULT NULL,
  `middle_name_owner` varchar(200) DEFAULT NULL,
  `sur_name_owner` varchar(200) DEFAULT NULL,
  `full_name_owner` varchar(401) DEFAULT NULL,
  `customer_name` varchar(250) DEFAULT NULL,
  `cust_address` varchar(800) DEFAULT NULL,
  `no_of_items` decimal(32,0) DEFAULT NULL,
  `price` decimal(32,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_vendors` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `iso_vendor` varchar(10) DEFAULT NULL,
  `f_local` tinyint(1) DEFAULT NULL,
  `f_use_same_sku` tinyint(4) DEFAULT NULL,
  `f_active` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_t_vendors_all`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_t_vendors_all` (
  `id` int(11) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `fk_id_module` int(11) DEFAULT NULL,
  `module_name` varchar(500) DEFAULT NULL,
  `fk_id_module_type` int(11) DEFAULT NULL,
  `module_type_name` varchar(50) DEFAULT NULL,
  `iso_module_type` varchar(10) DEFAULT NULL,
  `f_active_module` tinyint(4) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `ent_name` varchar(200) DEFAULT NULL,
  `ent_address` varchar(500) DEFAULT NULL,
  `ent_phone` varchar(100) DEFAULT NULL,
  `f_active_ent` tinyint(4) DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `iso_vendor` varchar(10) DEFAULT NULL,
  `f_local` tinyint(1) DEFAULT NULL,
  `f_use_same_sku` tinyint(4) DEFAULT NULL,
  `f_active` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `v_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_users` (
  `id` int(11) DEFAULT NULL,
  `login_id` varchar(150) DEFAULT NULL,
  `pass` varchar(50) DEFAULT NULL,
  `fk_id_account` int(11) DEFAULT NULL,
  `f_login` tinyint(4) DEFAULT NULL,
  `fk_id_ent` int(11) DEFAULT NULL,
  `iso_ent` varchar(10) DEFAULT NULL,
  `account_code` varchar(10) DEFAULT NULL,
  `first_name` varchar(200) DEFAULT NULL,
  `middle_name` varchar(200) DEFAULT NULL,
  `sur_name` varchar(200) DEFAULT NULL,
  `title` varchar(20) DEFAULT NULL,
  `full_name` varchar(401) DEFAULT NULL,
  `account_types` mediumtext DEFAULT NULL,
  `iso_account_types` mediumtext DEFAULT NULL,
  `f_active_account` int(11) DEFAULT NULL,
  `f1` varchar(401) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `va_statements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `va_statements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `fk_id_va` int(11) NOT NULL,
  `period_month` date NOT NULL,
  `fk_id_batch` int(11) NOT NULL,
  `version_no` smallint(6) NOT NULL DEFAULT 1,
  `sale_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `buy_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `fee_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `expenses` decimal(14,2) NOT NULL DEFAULT 0.00,
  `expenses_detail` text DEFAULT NULL,
  `profit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `va_share_basis` varchar(30) NOT NULL,
  `va_share_pct` decimal(5,2) DEFAULT NULL,
  `va_share_amount` decimal(14,2) DEFAULT NULL,
  `net_to_store` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `pdf_file` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_va_stmt` (`fk_id_branch`,`fk_id_va`,`period_month`,`version_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='VA monthly statement (payslip), 19 form fields (R-A_SALES-65)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wa_outbound`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_outbound` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_account` int(11) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `template_code` varchar(40) DEFAULT NULL,
  `body` text NOT NULL,
  `link_table` varchar(64) DEFAULT NULL,
  `link_id` int(11) DEFAULT NULL,
  `status` enum('QUEUED','SENT','DELIVERED','READ','FAILED') NOT NULL DEFAULT 'QUEUED',
  `provider_msg_id` varchar(80) DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  `queued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `k_acc` (`fk_id_account`,`queued_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wa_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wa_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `title` varchar(120) NOT NULL,
  `audience` enum('VA','TL','PARTNER','INVESTOR','SUPPLIER','OWNER','FINANCE','SOURCING') NOT NULL,
  `body` text NOT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `wa_templates` VALUES
(1,'TASK_NEW','New task','VA','Hi {name}, new task: {task}. Due {due}. Open: {link}',1),
(2,'TASK_DUE','Task due today','VA','Reminder {name}: {task} is due today. Reply DONE with a photo if evidence is required. {link}',1),
(3,'APPROVAL','Approval needed','OWNER','{who} asks approval: {what} â€“ {amount}. Approve / reject: {link}',1),
(4,'CAPITAL_CALL','Capital call','PARTNER','Capital call {amount} for {cycle}, due {due}. Accept in your Partner Account: {link}',1),
(5,'STATEMENT','Monthly statement','PARTNER','Your {month} statement is ready: profit {profit}, balance {balance}. {link}',1),
(6,'ICC_RED','Order now','SOURCING','Order now: {qty} Ã— {sku} â€“ {late} days late, stock-out {date}. {link}',1),
(7,'SUPPLIER_PAY_DUE','Supplier payment due','FINANCE','{supplier} {kind} {amount} due {due} for {po}. {link}',1);
DROP TABLE IF EXISTS `wh_box_sizes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_box_sizes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `box_code` varchar(30) NOT NULL,
  `inner_l_mm` smallint(6) NOT NULL,
  `inner_w_mm` smallint(6) NOT NULL,
  `inner_h_mm` smallint(6) NOT NULL,
  `max_weight_g` int(11) DEFAULT NULL,
  `tare_g` int(11) DEFAULT NULL,
  `fk_id_packing_product` int(11) DEFAULT NULL,
  `f_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_whbox` (`fk_id_ent`,`box_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Inner sizes of the packing boxes of list WH_BOX (R-H-29 box suggestion)';
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `wh_box_sizes` VALUES
(1,0,'MAILER_S',250,175,30,1000,15,NULL,1),
(2,0,'MAILER_L',400,300,60,2000,30,NULL,1),
(3,0,'BOX_S',200,150,100,5000,120,NULL,1),
(4,0,'BOX_M',300,220,150,10000,220,NULL,1),
(5,0,'BOX_L',450,350,250,20000,450,NULL,1);
DROP TABLE IF EXISTS `wh_handover_scans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_handover_scans` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_handover_session` int(11) NOT NULL,
  `scanned_code` varchar(100) NOT NULL,
  `fk_id_parcel` int(11) DEFAULT NULL,
  `result` varchar(14) NOT NULL,
  `f_resolved` tinyint(4) NOT NULL DEFAULT 0,
  `resolve_note` varchar(255) DEFAULT NULL,
  `client_op_id` char(36) DEFAULT NULL,
  `scanned_by` int(11) NOT NULL,
  `scanned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_hovs` (`fk_id_handover_session`),
  KEY `ix_hovs_parcel` (`fk_id_parcel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Each scan of a handover session (expected and exceptions)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_handover_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_handover_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `session_code` varchar(30) NOT NULL,
  `fk_id_site` int(11) NOT NULL,
  `fk_id_carrier` int(11) NOT NULL,
  `handover_mode` varchar(12) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'OPEN',
  `expected_count` int(11) NOT NULL DEFAULT 0,
  `scanned_count` int(11) NOT NULL DEFAULT 0,
  `exception_count` int(11) NOT NULL DEFAULT 0,
  `driver_name` varchar(120) DEFAULT NULL,
  `signature_file` varchar(255) DEFAULT NULL,
  `photo_file` varchar(255) DEFAULT NULL,
  `manifest_file` varchar(255) DEFAULT NULL,
  `opened_by` int(11) NOT NULL,
  `opened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `closed_by` int(11) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hov_code` (`session_code`),
  KEY `ix_hov` (`fk_id_site`,`fk_id_carrier`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Handover of parcels to a carrier';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_migration_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_migration_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_site` int(11) NOT NULL,
  `fk_id_old_location` int(11) NOT NULL,
  `fk_id_bin` int(11) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_whmm` (`fk_id_site`,`fk_id_old_location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Old shelf → new bin for the go-live migration (R-H-14 partial placement)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_movements` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_site` int(11) NOT NULL,
  `fk_id_product` int(11) NOT NULL,
  `fk_id_owner_seg` int(11) NOT NULL DEFAULT 0,
  `move_type` varchar(16) NOT NULL,
  `fk_id_from_location` int(11) DEFAULT NULL,
  `fk_id_to_location` int(11) DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `from_qty_after` int(11) DEFAULT NULL,
  `to_qty_after` int(11) DEFAULT NULL,
  `source_type` varchar(20) DEFAULT NULL,
  `source_id` bigint(20) DEFAULT NULL,
  `fk_id_stock_movement` bigint(20) DEFAULT NULL,
  `reason_code` varchar(30) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `client_op_id` char(36) DEFAULT NULL,
  `device_ref` varchar(80) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_whm_prod` (`fk_id_product`,`created_at`),
  KEY `ix_whm_from` (`fk_id_from_location`),
  KEY `ix_whm_to` (`fk_id_to_location`),
  KEY `ix_whm_source` (`source_type`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Physical stock ledger per bin (append-only; corrections are new lines)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_parcel_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_parcel_lines` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_parcel` int(11) NOT NULL,
  `fk_id_sale` bigint(20) NOT NULL,
  `fk_id_product` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `qty_packed` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_parcel_line` (`fk_id_parcel`,`fk_id_sale`,`fk_id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Items in a parcel (pack verification)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_parcels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_parcels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `parcel_code` varchar(30) NOT NULL,
  `fk_id_branch` int(11) NOT NULL,
  `order_no` varchar(60) NOT NULL,
  `fk_id_site` int(11) NOT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `fk_id_car_pkg_svc` int(11) DEFAULT NULL,
  `fk_id_box` int(11) DEFAULT NULL,
  `weight_g` int(11) DEFAULT NULL,
  `length_cm` decimal(8,2) DEFAULT NULL,
  `width_cm` decimal(8,2) DEFAULT NULL,
  `height_cm` decimal(8,2) DEFAULT NULL,
  `tracking_no` varchar(60) DEFAULT NULL,
  `label_file` varchar(255) DEFAULT NULL,
  `label_status` varchar(12) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'OPEN',
  `resend_of` int(11) DEFAULT NULL,
  `fk_id_handover_session` int(11) DEFAULT NULL,
  `handed_over_at` datetime DEFAULT NULL,
  `claim_ref` varchar(60) DEFAULT NULL,
  `claim_amount` decimal(14,2) DEFAULT NULL,
  `fk_id_claim_currency` int(11) DEFAULT NULL,
  `claim_status` varchar(12) DEFAULT NULL,
  `packed_by` int(11) DEFAULT NULL,
  `packed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_parcel_code` (`parcel_code`),
  KEY `ix_parcel_track` (`tracking_no`),
  KEY `ix_parcel_order` (`fk_id_branch`,`order_no`),
  KEY `ix_parcel_status` (`fk_id_site`,`status`,`fk_id_carrier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Outbound parcel (one tracking number)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_pick_task_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_pick_task_lines` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_pick_task` int(11) NOT NULL,
  `line_no` smallint(6) NOT NULL,
  `fk_id_product` int(11) NOT NULL,
  `fk_id_from_location` int(11) DEFAULT NULL,
  `fk_id_to_location` int(11) DEFAULT NULL,
  `fk_id_reservation` bigint(20) DEFAULT NULL,
  `fk_id_sale` bigint(20) DEFAULT NULL,
  `slot_no` smallint(6) DEFAULT NULL,
  `fk_id_fba_carton` int(11) DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `qty_done` int(11) NOT NULL DEFAULT 0,
  `qty_short` int(11) NOT NULL DEFAULT 0,
  `line_status` varchar(10) NOT NULL DEFAULT 'OPEN',
  `done_by` int(11) DEFAULT NULL,
  `done_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_wh_tl` (`fk_id_pick_task`,`line_no`),
  KEY `ix_wh_tl_res` (`fk_id_reservation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Lines of a warehouse work task';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_pick_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_pick_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `task_code` varchar(30) NOT NULL,
  `fk_id_site` int(11) NOT NULL,
  `task_type` varchar(12) NOT NULL,
  `pick_mode` varchar(8) DEFAULT NULL,
  `fk_id_zone` int(11) DEFAULT NULL,
  `fk_id_carrier` int(11) DEFAULT NULL,
  `fk_id_fba_shipment` int(11) DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'OPEN',
  `assigned_to` int(11) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `last_activity_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wh_task` (`task_code`),
  KEY `ix_wh_task_open` (`fk_id_site`,`status`,`task_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Warehouse work task (pick list, putaway, replenishment …)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_printer_defaults`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_printer_defaults` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `workstation` varchar(80) NOT NULL DEFAULT '*',
  `label_kind` varchar(20) NOT NULL,
  `fk_id_printer_profile` int(11) NOT NULL,
  `fk_id_label_template` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wpd` (`fk_id_ent`,`workstation`,`label_kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Default printer per label kind and workstation';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_product_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_product_stats` (
  `fk_id_product` int(11) NOT NULL,
  `fk_id_site` int(11) NOT NULL,
  `abc_class` char(1) DEFAULT NULL,
  `units_period` int(11) NOT NULL DEFAULT 0,
  `pick_lines_period` int(11) NOT NULL DEFAULT 0,
  `next_count_due` date DEFAULT NULL,
  `computed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`fk_id_product`,`fk_id_site`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Nightly ABC class and count schedule per SKU and site';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_recon_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_recon_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_site` int(11) NOT NULL,
  `run_at` datetime NOT NULL DEFAULT current_timestamp(),
  `skus_checked` int(11) NOT NULL DEFAULT 0,
  `mismatches` int(11) NOT NULL DEFAULT 0,
  `detail_json` mediumtext DEFAULT NULL,
  `run_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Reconciliation results (R-H-54)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_reservation_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_reservation_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_reservation` bigint(20) NOT NULL,
  `old_status` varchar(10) DEFAULT NULL,
  `new_status` varchar(10) NOT NULL,
  `old_qty` int(11) DEFAULT NULL,
  `new_qty` int(11) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_resh` (`fk_id_reservation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Every reservation change (who, when, why)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_reservations` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_site` int(11) NOT NULL,
  `fk_id_sale` bigint(20) DEFAULT NULL,
  `fk_id_fba_shipment` int(11) DEFAULT NULL,
  `fk_id_product` int(11) NOT NULL,
  `owner_rule` varchar(10) NOT NULL,
  `fk_id_owner_seg` int(11) NOT NULL DEFAULT 0,
  `qty` int(11) NOT NULL,
  `qty_short` int(11) NOT NULL DEFAULT 0,
  `qty_picked` int(11) NOT NULL DEFAULT 0,
  `status` varchar(10) NOT NULL DEFAULT 'OPEN',
  `reason` varchar(20) NOT NULL DEFAULT 'ORDER',
  `dispatch_by` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `release_reason` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_res_line` (`fk_id_sale`,`fk_id_product`),
  KEY `ix_res_open` (`fk_id_product`,`status`),
  KEY `ix_res_site` (`fk_id_site`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Units promised to order lines or inbound shipments (not ledger lines)';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_return_intakes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_return_intakes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_ent` int(11) NOT NULL,
  `intake_code` varchar(30) NOT NULL,
  `fk_id_site` int(11) NOT NULL,
  `scanned_code` varchar(100) NOT NULL,
  `resolved_as` varchar(12) DEFAULT NULL,
  `fk_id_branch` int(11) DEFAULT NULL,
  `fk_id_sale` bigint(20) DEFAULT NULL,
  `fk_id_product` int(11) DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `grade` varchar(24) DEFAULT NULL,
  `photo_file` varchar(255) DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'UNMATCHED',
  `note` text DEFAULT NULL,
  `client_op_id` char(36) DEFAULT NULL,
  `received_by` int(11) NOT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rti_code` (`intake_code`),
  KEY `ix_rti_status` (`fk_id_site`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Return scans; unmatched ones wait here with no stock movement';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_scan_ops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_scan_ops` (
  `op_id` char(36) NOT NULL,
  `fk_id_ent` int(11) NOT NULL,
  `action` varchar(30) NOT NULL,
  `scanned_code` varchar(120) DEFAULT NULL,
  `resolved_as` varchar(12) DEFAULT NULL,
  `result_json` text DEFAULT NULL,
  `device_ref` varchar(80) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`op_id`),
  KEY `ix_scan_user` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Every scan / action with its op id – idempotency and audit';
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wh_slotting_suggestions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wh_slotting_suggestions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fk_id_site` int(11) NOT NULL,
  `fk_id_product` int(11) NOT NULL,
  `fk_id_from_location` int(11) NOT NULL,
  `fk_id_to_location` int(11) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'OPEN',
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_slot` (`fk_id_site`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='ABC slotting suggestions (never automatic)';
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

