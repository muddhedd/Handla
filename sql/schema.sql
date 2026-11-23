-- ============================================================
-- Handla: tabeller
-- Kör i phpMyAdmin (fliken SQL) eller: mysql -u ANVÄNDARE -p DATABAS < schema.sql
-- ============================================================

-- Butiker, t.ex. Willys
CREATE TABLE IF NOT EXISTS HandlaButiker (
    ID      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    UserID  INT NOT NULL,
    Namn    VARCHAR(100) NOT NULL,
    PRIMARY KEY (ID),
    UNIQUE KEY uniq_butik (UserID, Namn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Varuregistret: varje vara finns en gång, oavsett hur många listor den ligger på
CREATE TABLE IF NOT EXISTS HandlaVaror (
    ID         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    UserID     INT NOT NULL,
    Namn       VARCHAR(150) NOT NULL,
    Avdelning  VARCHAR(100) NOT NULL DEFAULT '',
    Skapad     DATETIME NOT NULL,
    PRIMARY KEY (ID),
    UNIQUE KEY uniq_vara (UserID, Namn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Listorna, t.ex. "Veckohandling" kopplad till Willys
CREATE TABLE IF NOT EXISTS HandlaListor (
    ID                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    UserID             INT NOT NULL,
    Namn               VARCHAR(150) NOT NULL,
    ButikID            INT UNSIGNED NULL,
    Tillfallig         TINYINT(1) NOT NULL DEFAULT 0,      -- 1 = ingen varustatistik
    Version            INT UNSIGNED NOT NULL DEFAULT 1,    -- ökas vid varje ändring (livesynk)
    AktivtTillfalleID  INT UNSIGNED NULL,                  -- satt medan man handlar
    Skapad             DATETIME NOT NULL,
    Andrad             DATETIME NOT NULL,
    PRIMARY KEY (ID),
    KEY idx_user (UserID),
    CONSTRAINT fk_lista_butik FOREIGN KEY (ButikID) REFERENCES HandlaButiker (ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Det som ligger på en lista just nu
CREATE TABLE IF NOT EXISTS HandlaRader (
    ID         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ListaID    INT UNSIGNED NOT NULL,
    VaraID     INT UNSIGNED NOT NULL,
    Antal      VARCHAR(50)  NOT NULL DEFAULT '',
    Kommentar  VARCHAR(255) NOT NULL DEFAULT '',
    Status     ENUM('kvar','bockad','saknas') NOT NULL DEFAULT 'kvar',
    BockadMs   BIGINT NULL,                          -- när den bockades av (ger plockordningen)
    Tillagd    DATETIME NOT NULL,
    PRIMARY KEY (ID),
    UNIQUE KEY uniq_rad (ListaID, VaraID),
    KEY idx_vara (VaraID),
    CONSTRAINT fk_rad_lista FOREIGN KEY (ListaID) REFERENCES HandlaListor (ID) ON DELETE CASCADE,
    CONSTRAINT fk_rad_vara  FOREIGN KEY (VaraID)  REFERENCES HandlaVaror (ID)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Handlingstillfällen: ett per "Börja handla" → "Klar"
CREATE TABLE IF NOT EXISTS HandlaTillfallen (
    ID          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    UserID      INT NOT NULL,
    ListaID     INT UNSIGNED NULL,
    ListNamn    VARCHAR(150) NOT NULL DEFAULT '',     -- kopia, finns kvar om listan tas bort
    ButikID     INT UNSIGNED NULL,
    ButikNamn   VARCHAR(100) NOT NULL DEFAULT '',     -- kopia, finns kvar om butiken tas bort
    Tillfallig  TINYINT(1) NOT NULL DEFAULT 0,
    Status      ENUM('pagar','klar') NOT NULL DEFAULT 'pagar',
    Startad     DATETIME NOT NULL,
    Avslutad    DATETIME NULL,
    Kostnad     DECIMAL(10,2) NULL,
    PRIMARY KEY (ID),
    KEY idx_user (UserID, Status, Avslutad),
    KEY idx_lista (ListaID, Status, Avslutad),
    CONSTRAINT fk_tillf_lista FOREIGN KEY (ListaID) REFERENCES HandlaListor (ID)  ON DELETE SET NULL,
    CONSTRAINT fk_tillf_butik FOREIGN KEY (ButikID) REFERENCES HandlaButiker (ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Vad som köptes vid varje tillfälle (grunden för all statistik)
CREATE TABLE IF NOT EXISTS HandlaKop (
    ID           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    TillfalleID  INT UNSIGNED NOT NULL,
    VaraID       INT UNSIGNED NOT NULL,
    Antal        VARCHAR(50)  NOT NULL DEFAULT '',
    Kommentar    VARCHAR(255) NOT NULL DEFAULT '',
    Ordning      INT NOT NULL,                        -- 1, 2, 3 … i avbockningsordning
    OrdningRel   DECIMAL(6,5) NOT NULL,               -- 0 = först, 1 = sist i butiken
    PRIMARY KEY (ID),
    KEY idx_tillf (TillfalleID),
    KEY idx_vara (VaraID),
    CONSTRAINT fk_kop_tillf FOREIGN KEY (TillfalleID) REFERENCES HandlaTillfallen (ID) ON DELETE CASCADE,
    CONSTRAINT fk_kop_vara  FOREIGN KEY (VaraID)      REFERENCES HandlaVaror (ID)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
