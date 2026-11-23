-- ============================================================
-- Handla: demo (tabeller + sandlådetabell + exempeldata)
-- Kör i phpMyAdmin (fliken SQL) eller: mysql -u ANVÄNDARE -p DATABAS < demo.sql
-- ============================================================
-- Exempeldatan läggs under UserID 0 och kopieras till varje besökares sandlåda.
-- Går att köra igen: den gamla mallen ersätts, besökarnas sandlådor rörs inte.

SET NAMES utf8mb4;

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

CREATE TABLE IF NOT EXISTS `DemoHandla` (
    ID      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Skapad  DATETIME NOT NULL,
    Senast  DATETIME NOT NULL,
    PRIMARY KEY (ID),
    KEY idx_senast (Senast)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- ---------- Exempeldata (mall) ----------
SET @uid = 0;

DELETE FROM HandlaTillfallen WHERE UserID = 0;
DELETE FROM HandlaListor WHERE UserID = 0;
DELETE FROM HandlaVaror WHERE UserID = 0;
DELETE FROM HandlaButiker WHERE UserID = 0;

INSERT INTO HandlaButiker (UserID, Namn) VALUES (@uid, 'Willys'); SET @b_willys = LAST_INSERT_ID();
INSERT INTO HandlaButiker (UserID, Namn) VALUES (@uid, 'ICA Kvantum'); SET @b_ica = LAST_INSERT_ID();

INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Bananer', 'Frukt & grönt', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v0 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Äpplen', 'Frukt & grönt', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v1 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Gurka', 'Frukt & grönt', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v2 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Tomater', 'Frukt & grönt', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v3 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Avokado', 'Frukt & grönt', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v4 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Potatis', 'Frukt & grönt', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v5 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Gul lök', 'Frukt & grönt', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v6 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Bröd', 'Bröd', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v7 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Knäckebröd', 'Bröd', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v8 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Kycklingfilé', 'Kött & fisk', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v9 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Köttfärs', 'Kött & fisk', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v10 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Lax', 'Kött & fisk', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v11 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Pasta', 'Skafferi', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v12 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Krossade tomater', 'Skafferi', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v13 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Havregryn', 'Skafferi', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v14 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Kaffe', 'Skafferi', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v15 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Ris', 'Skafferi', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v16 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Mjölk', 'Kyl', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v17 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Yoghurt', 'Kyl', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v18 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Fil', 'Kyl', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v19 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Smör', 'Kyl', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v20 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Ost', 'Kyl', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v21 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Ägg', 'Kyl', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v22 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Fryst spenat', 'Frys', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v23 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Glass', 'Frys', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v24 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Toalettpapper', 'Hushåll', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v25 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Hushållspapper', 'Hushåll', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v26 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Tvättmedel', 'Hushåll', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v27 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Diskmedel', 'Hushåll', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v28 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Choklad', 'Godis', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v29 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Tacoskal', 'Skafferi', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v30 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Gräddfil', 'Kyl', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v31 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Chips', 'Godis', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v32 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Salsa', 'Skafferi', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v33 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Korv', 'Kött & fisk', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v34 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Grillkol', 'Säsong', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v35 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Korvbröd', 'Bröd', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v36 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Senap', 'Skafferi', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v37 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Ketchup', 'Skafferi', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v38 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Läsk', 'Dryck', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v39 = LAST_INSERT_ID();
INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (@uid, 'Batterier', '', DATE_SUB(NOW(), INTERVAL 144000 MINUTE)); SET @v40 = LAST_INSERT_ID();

INSERT INTO HandlaListor (UserID, Namn, ButikID, Tillfallig, Version, AktivtTillfalleID, Skapad, Andrad) VALUES (@uid, 'Veckohandling', @b_willys, 0, 1, NULL, DATE_SUB(NOW(), INTERVAL 144000 MINUTE), DATE_SUB(NOW(), INTERVAL 8640 MINUTE)); SET @l_vecka = LAST_INSERT_ID();
INSERT INTO HandlaListor (UserID, Namn, ButikID, Tillfallig, Version, AktivtTillfalleID, Skapad, Andrad) VALUES (@uid, 'Helgmys', @b_ica, 0, 1, NULL, DATE_SUB(NOW(), INTERVAL 144000 MINUTE), DATE_SUB(NOW(), INTERVAL 8640 MINUTE)); SET @l_helg = LAST_INSERT_ID();
INSERT INTO HandlaListor (UserID, Namn, ButikID, Tillfallig, Version, AktivtTillfalleID, Skapad, Andrad) VALUES (@uid, 'Grillfest', @b_ica, 1, 1, NULL, DATE_SUB(NOW(), INTERVAL 144000 MINUTE), DATE_SUB(NOW(), INTERVAL 8640 MINUTE)); SET @l_grill = LAST_INSERT_ID();

INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_vecka, 'Veckohandling', @b_willys, 'Willys', 0, 'klar', DATE_SUB(NOW(), INTERVAL 119611 MINUTE), DATE_SUB(NOW(), INTERVAL 119576 MINUTE), 473.08); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v0, '1 klase', '', 1, 0.00000),
  (@t, @v1, '', '', 2, 0.07692),
  (@t, @v2, '', '', 3, 0.15385),
  (@t, @v3, '', '', 4, 0.23077),
  (@t, @v7, '', '', 5, 0.30769),
  (@t, @v9, '', '', 6, 0.38462),
  (@t, @v10, '', '', 7, 0.46154),
  (@t, @v13, '', '', 8, 0.53846),
  (@t, @v14, '', '', 9, 0.61538),
  (@t, @v18, '', '', 10, 0.69231),
  (@t, @v17, '2 st', '', 11, 0.76923),
  (@t, @v22, '12 st', '', 12, 0.84615),
  (@t, @v23, '', '', 13, 0.92308),
  (@t, @v29, '', '', 14, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_vecka, 'Veckohandling', @b_willys, 'Willys', 0, 'klar', DATE_SUB(NOW(), INTERVAL 109492 MINUTE), DATE_SUB(NOW(), INTERVAL 109457 MINUTE), 815.26); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v1, '', '', 1, 0.00000),
  (@t, @v0, '1 klase', '', 2, 0.05000),
  (@t, @v2, '', '', 3, 0.10000),
  (@t, @v3, '', '', 4, 0.15000),
  (@t, @v4, '', '', 5, 0.20000),
  (@t, @v6, '', '', 6, 0.25000),
  (@t, @v5, '', '', 7, 0.30000),
  (@t, @v7, '', '', 8, 0.35000),
  (@t, @v8, '', '', 9, 0.40000),
  (@t, @v9, '', '', 10, 0.45000),
  (@t, @v11, '', '', 11, 0.50000),
  (@t, @v15, '2 pkt', '', 12, 0.55000),
  (@t, @v18, '', '', 13, 0.60000),
  (@t, @v17, '2 st', '', 14, 0.65000),
  (@t, @v20, '', '', 15, 0.70000),
  (@t, @v19, '', '', 16, 0.75000),
  (@t, @v21, '', '', 17, 0.80000),
  (@t, @v22, '12 st', '', 18, 0.85000),
  (@t, @v24, '', '', 19, 0.90000),
  (@t, @v25, '', '', 20, 0.95000),
  (@t, @v28, '', '', 21, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_vecka, 'Veckohandling', @b_willys, 'Willys', 0, 'klar', DATE_SUB(NOW(), INTERVAL 99436 MINUTE), DATE_SUB(NOW(), INTERVAL 99401 MINUTE), 490.30); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v0, '1 klase', '', 1, 0.00000),
  (@t, @v1, '', '', 2, 0.07692),
  (@t, @v2, '', '', 3, 0.15385),
  (@t, @v3, '', '', 4, 0.23077),
  (@t, @v7, '', '', 5, 0.30769),
  (@t, @v9, '', '', 6, 0.38462),
  (@t, @v10, '', '', 7, 0.46154),
  (@t, @v12, '', '', 8, 0.53846),
  (@t, @v13, '', '', 9, 0.61538),
  (@t, @v17, '2 st', '', 10, 0.69231),
  (@t, @v18, '', '', 11, 0.76923),
  (@t, @v22, '12 st', '', 12, 0.84615),
  (@t, @v24, '', '', 13, 0.92308),
  (@t, @v29, '', '', 14, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_vecka, 'Veckohandling', @b_willys, 'Willys', 0, 'klar', DATE_SUB(NOW(), INTERVAL 89505 MINUTE), DATE_SUB(NOW(), INTERVAL 89470 MINUTE), NULL); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v1, '', '', 1, 0.00000),
  (@t, @v0, '1 klase', '', 2, 0.05882),
  (@t, @v2, '', '', 3, 0.11765),
  (@t, @v4, '', '', 4, 0.17647),
  (@t, @v3, '', '', 5, 0.23529),
  (@t, @v5, '', '', 6, 0.29412),
  (@t, @v7, '', '', 7, 0.35294),
  (@t, @v9, '', '', 8, 0.41176),
  (@t, @v11, '', '', 9, 0.47059),
  (@t, @v15, '2 pkt', '', 10, 0.52941),
  (@t, @v14, '', '', 11, 0.58824),
  (@t, @v16, '', '', 12, 0.64706),
  (@t, @v17, '2 st', '', 13, 0.70588),
  (@t, @v18, '', '', 14, 0.76471),
  (@t, @v19, '', '', 15, 0.82353),
  (@t, @v21, '', '', 16, 0.88235),
  (@t, @v22, '12 st', '', 17, 0.94118),
  (@t, @v24, '', '', 18, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_vecka, 'Veckohandling', @b_willys, 'Willys', 0, 'klar', DATE_SUB(NOW(), INTERVAL 79455 MINUTE), DATE_SUB(NOW(), INTERVAL 79420 MINUTE), 696.29); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v0, '1 klase', '', 1, 0.00000),
  (@t, @v1, '', '', 2, 0.06250),
  (@t, @v2, '', '', 3, 0.12500),
  (@t, @v3, '', '', 4, 0.18750),
  (@t, @v6, '', '', 5, 0.25000),
  (@t, @v7, '', '', 6, 0.31250),
  (@t, @v8, '', '', 7, 0.37500),
  (@t, @v9, '', '', 8, 0.43750),
  (@t, @v10, '', '', 9, 0.50000),
  (@t, @v13, '', '', 10, 0.56250),
  (@t, @v18, '', '', 11, 0.62500),
  (@t, @v17, '2 st', '', 12, 0.68750),
  (@t, @v20, '', '', 13, 0.75000),
  (@t, @v22, '12 st', '', 14, 0.81250),
  (@t, @v27, '', '', 15, 0.87500),
  (@t, @v26, '', '', 16, 0.93750),
  (@t, @v29, '', '', 17, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_vecka, 'Veckohandling', @b_willys, 'Willys', 0, 'klar', DATE_SUB(NOW(), INTERVAL 69196 MINUTE), DATE_SUB(NOW(), INTERVAL 69161 MINUTE), 747.41); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v0, '1 klase', '', 1, 0.00000),
  (@t, @v1, '', '', 2, 0.05882),
  (@t, @v3, '', '', 3, 0.11765),
  (@t, @v2, '', '', 4, 0.17647),
  (@t, @v4, '', '', 5, 0.23529),
  (@t, @v5, '', '', 6, 0.29412),
  (@t, @v7, '', '', 7, 0.35294),
  (@t, @v9, '', '', 8, 0.41176),
  (@t, @v11, '', '', 9, 0.47059),
  (@t, @v12, '', '', 10, 0.52941),
  (@t, @v15, '2 pkt', '', 11, 0.58824),
  (@t, @v18, '', '', 12, 0.64706),
  (@t, @v17, '2 st', '', 13, 0.70588),
  (@t, @v19, '', '', 14, 0.76471),
  (@t, @v21, '', '', 15, 0.82353),
  (@t, @v22, '12 st', '', 16, 0.88235),
  (@t, @v25, '', '', 17, 0.94118),
  (@t, @v29, '', '', 18, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_vecka, 'Veckohandling', @b_willys, 'Willys', 0, 'klar', DATE_SUB(NOW(), INTERVAL 59269 MINUTE), DATE_SUB(NOW(), INTERVAL 59234 MINUTE), 453.16); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v0, '1 klase', '', 1, 0.00000),
  (@t, @v1, '', '', 2, 0.08333),
  (@t, @v2, '', '', 3, 0.16667),
  (@t, @v3, '', '', 4, 0.25000),
  (@t, @v7, '', '', 5, 0.33333),
  (@t, @v10, '', '', 6, 0.41667),
  (@t, @v9, '', '', 7, 0.50000),
  (@t, @v13, '', '', 8, 0.58333),
  (@t, @v14, '', '', 9, 0.66667),
  (@t, @v18, '', '', 10, 0.75000),
  (@t, @v17, '2 st', '', 11, 0.83333),
  (@t, @v22, '12 st', '', 12, 0.91667),
  (@t, @v23, '', '', 13, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_vecka, 'Veckohandling', @b_willys, 'Willys', 0, 'klar', DATE_SUB(NOW(), INTERVAL 48996 MINUTE), DATE_SUB(NOW(), INTERVAL 48961 MINUTE), 736.82); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v1, '', '', 1, 0.00000),
  (@t, @v0, '1 klase', '', 2, 0.05556),
  (@t, @v2, '', '', 3, 0.11111),
  (@t, @v3, '', '', 4, 0.16667),
  (@t, @v4, '', '', 5, 0.22222),
  (@t, @v5, '', '', 6, 0.27778),
  (@t, @v6, '', '', 7, 0.33333),
  (@t, @v7, '', '', 8, 0.38889),
  (@t, @v8, '', '', 9, 0.44444),
  (@t, @v9, '', '', 10, 0.50000),
  (@t, @v11, '', '', 11, 0.55556),
  (@t, @v15, '2 pkt', '', 12, 0.61111),
  (@t, @v19, '', '', 13, 0.66667),
  (@t, @v18, '', '', 14, 0.72222),
  (@t, @v20, '', '', 15, 0.77778),
  (@t, @v17, '2 st', '', 16, 0.83333),
  (@t, @v21, '', '', 17, 0.88889),
  (@t, @v22, '12 st', '', 18, 0.94444),
  (@t, @v24, '', '', 19, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_vecka, 'Veckohandling', @b_willys, 'Willys', 0, 'klar', DATE_SUB(NOW(), INTERVAL 39025 MINUTE), DATE_SUB(NOW(), INTERVAL 38990 MINUTE), NULL); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v2, '', '', 1, 0.00000),
  (@t, @v0, '1 klase', '', 2, 0.07692),
  (@t, @v1, '', '', 3, 0.15385),
  (@t, @v3, '', '', 4, 0.23077),
  (@t, @v7, '', '', 5, 0.30769),
  (@t, @v9, '', '', 6, 0.38462),
  (@t, @v10, '', '', 7, 0.46154),
  (@t, @v13, '', '', 8, 0.53846),
  (@t, @v12, '', '', 9, 0.61538),
  (@t, @v16, '', '', 10, 0.69231),
  (@t, @v17, '2 st', '', 11, 0.76923),
  (@t, @v18, '', '', 12, 0.84615),
  (@t, @v22, '12 st', '', 13, 0.92308),
  (@t, @v29, '', '', 14, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_vecka, 'Veckohandling', @b_willys, 'Willys', 0, 'klar', DATE_SUB(NOW(), INTERVAL 28973 MINUTE), DATE_SUB(NOW(), INTERVAL 28938 MINUTE), 821.61); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v1, '', '', 1, 0.00000),
  (@t, @v0, '1 klase', '', 2, 0.05556),
  (@t, @v2, '', '', 3, 0.11111),
  (@t, @v4, '', '', 4, 0.16667),
  (@t, @v3, '', '', 5, 0.22222),
  (@t, @v5, '', '', 6, 0.27778),
  (@t, @v7, '', '', 7, 0.33333),
  (@t, @v9, '', '', 8, 0.38889),
  (@t, @v11, '', '', 9, 0.44444),
  (@t, @v15, '2 pkt', '', 10, 0.50000),
  (@t, @v14, '', '', 11, 0.55556),
  (@t, @v17, '2 st', '', 12, 0.61111),
  (@t, @v18, '', '', 13, 0.66667),
  (@t, @v21, '', '', 14, 0.72222),
  (@t, @v19, '', '', 15, 0.77778),
  (@t, @v22, '12 st', '', 16, 0.83333),
  (@t, @v26, '', '', 17, 0.88889),
  (@t, @v25, '', '', 18, 0.94444),
  (@t, @v28, '', '', 19, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_vecka, 'Veckohandling', @b_willys, 'Willys', 0, 'klar', DATE_SUB(NOW(), INTERVAL 18938 MINUTE), DATE_SUB(NOW(), INTERVAL 18903 MINUTE), 591.29); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v1, '', '', 1, 0.00000),
  (@t, @v0, '1 klase', '', 2, 0.07143),
  (@t, @v2, '', '', 3, 0.14286),
  (@t, @v3, '', '', 4, 0.21429),
  (@t, @v6, '', '', 5, 0.28571),
  (@t, @v7, '', '', 6, 0.35714),
  (@t, @v8, '', '', 7, 0.42857),
  (@t, @v10, '', '', 8, 0.50000),
  (@t, @v9, '', '', 9, 0.57143),
  (@t, @v13, '', '', 10, 0.64286),
  (@t, @v17, '2 st', '', 11, 0.71429),
  (@t, @v18, '', '', 12, 0.78571),
  (@t, @v20, '', '', 13, 0.85714),
  (@t, @v22, '12 st', '', 14, 0.92857),
  (@t, @v27, '', '', 15, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_vecka, 'Veckohandling', @b_willys, 'Willys', 0, 'klar', DATE_SUB(NOW(), INTERVAL 8685 MINUTE), DATE_SUB(NOW(), INTERVAL 8650 MINUTE), 740.46); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v0, '1 klase', '', 1, 0.00000),
  (@t, @v2, '', '', 2, 0.05882),
  (@t, @v1, '', '', 3, 0.11765),
  (@t, @v3, '', '', 4, 0.17647),
  (@t, @v4, '', '', 5, 0.23529),
  (@t, @v5, '', '', 6, 0.29412),
  (@t, @v7, '', '', 7, 0.35294),
  (@t, @v9, '', '', 8, 0.41176),
  (@t, @v11, '', '', 9, 0.47059),
  (@t, @v12, '', '', 10, 0.52941),
  (@t, @v15, '2 pkt', '', 11, 0.58824),
  (@t, @v17, '2 st', '', 12, 0.64706),
  (@t, @v18, '', '', 13, 0.70588),
  (@t, @v19, '', '', 14, 0.76471),
  (@t, @v21, '', '', 15, 0.82353),
  (@t, @v22, '12 st', '', 16, 0.88235),
  (@t, @v24, '', '', 17, 0.94118),
  (@t, @v29, '', '', 18, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_helg, 'Helgmys', @b_ica, 'ICA Kvantum', 0, 'klar', DATE_SUB(NOW(), INTERVAL 108227 MINUTE), DATE_SUB(NOW(), INTERVAL 108192 MINUTE), 243.15); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v11, '', '', 1, 0.00000),
  (@t, @v30, '', '', 2, 0.25000),
  (@t, @v31, '', '', 3, 0.50000),
  (@t, @v24, '', '', 4, 0.75000),
  (@t, @v32, '', '', 5, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_helg, 'Helgmys', @b_ica, 'ICA Kvantum', 0, 'klar', DATE_SUB(NOW(), INTERVAL 77934 MINUTE), DATE_SUB(NOW(), INTERVAL 77899 MINUTE), 261.77); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v11, '', '', 1, 0.00000),
  (@t, @v30, '', '', 2, 0.20000),
  (@t, @v33, '', '', 3, 0.40000),
  (@t, @v31, '', '', 4, 0.60000),
  (@t, @v24, '', '', 5, 0.80000),
  (@t, @v32, '', '', 6, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_helg, 'Helgmys', @b_ica, 'ICA Kvantum', 0, 'klar', DATE_SUB(NOW(), INTERVAL 47696 MINUTE), DATE_SUB(NOW(), INTERVAL 47661 MINUTE), 223.86); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v11, '', '', 1, 0.00000),
  (@t, @v33, '', '', 2, 0.25000),
  (@t, @v30, '', '', 3, 0.50000),
  (@t, @v24, '', '', 4, 0.75000),
  (@t, @v32, '', '', 5, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_helg, 'Helgmys', @b_ica, 'ICA Kvantum', 0, 'klar', DATE_SUB(NOW(), INTERVAL 17326 MINUTE), DATE_SUB(NOW(), INTERVAL 17291 MINUTE), 145.43); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v30, '', '', 1, 0.00000),
  (@t, @v33, '', '', 2, 0.25000),
  (@t, @v31, '', '', 3, 0.50000),
  (@t, @v24, '', '', 4, 0.75000),
  (@t, @v32, '', '', 5, 1.00000);
INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad) VALUES (@uid, @l_grill, 'Grillfest', @b_ica, 'ICA Kvantum', 1, 'klar', DATE_SUB(NOW(), INTERVAL 57637 MINUTE), DATE_SUB(NOW(), INTERVAL 57602 MINUTE), 232.03); SET @t = LAST_INSERT_ID();
INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES
  (@t, @v35, '', '', 1, 0.00000),
  (@t, @v34, '', '', 2, 0.20000),
  (@t, @v36, '', '', 3, 0.40000),
  (@t, @v37, '', '', 4, 0.60000),
  (@t, @v38, '', '', 5, 0.80000),
  (@t, @v39, '', '', 6, 1.00000);

INSERT INTO HandlaRader (ListaID, VaraID, Antal, Kommentar, Status, BockadMs, Tillagd) VALUES (@l_vecka, @v17, '2 st', '', 'kvar', NULL, DATE_SUB(NOW(), INTERVAL 2880 MINUTE));
INSERT INTO HandlaRader (ListaID, VaraID, Antal, Kommentar, Status, BockadMs, Tillagd) VALUES (@l_vecka, @v7, '', 'grovt', 'kvar', NULL, DATE_SUB(NOW(), INTERVAL 2880 MINUTE));
INSERT INTO HandlaRader (ListaID, VaraID, Antal, Kommentar, Status, BockadMs, Tillagd) VALUES (@l_vecka, @v15, '2 pkt', 'mellanrost', 'kvar', NULL, DATE_SUB(NOW(), INTERVAL 2880 MINUTE));
INSERT INTO HandlaRader (ListaID, VaraID, Antal, Kommentar, Status, BockadMs, Tillagd) VALUES (@l_vecka, @v21, '', '', 'kvar', NULL, DATE_SUB(NOW(), INTERVAL 2880 MINUTE));
INSERT INTO HandlaRader (ListaID, VaraID, Antal, Kommentar, Status, BockadMs, Tillagd) VALUES (@l_vecka, @v40, 'AA', '', 'kvar', NULL, DATE_SUB(NOW(), INTERVAL 2880 MINUTE));
INSERT INTO HandlaRader (ListaID, VaraID, Antal, Kommentar, Status, BockadMs, Tillagd) VALUES (@l_vecka, @v3, '', '', 'kvar', NULL, DATE_SUB(NOW(), INTERVAL 2880 MINUTE));
INSERT INTO HandlaRader (ListaID, VaraID, Antal, Kommentar, Status, BockadMs, Tillagd) VALUES (@l_helg, @v30, '', '', 'kvar', NULL, DATE_SUB(NOW(), INTERVAL 2880 MINUTE));
INSERT INTO HandlaRader (ListaID, VaraID, Antal, Kommentar, Status, BockadMs, Tillagd) VALUES (@l_helg, @v31, '', '', 'kvar', NULL, DATE_SUB(NOW(), INTERVAL 2880 MINUTE));
INSERT INTO HandlaRader (ListaID, VaraID, Antal, Kommentar, Status, BockadMs, Tillagd) VALUES (@l_grill, @v34, '2 pkt', '', 'kvar', NULL, DATE_SUB(NOW(), INTERVAL 2880 MINUTE));
INSERT INTO HandlaRader (ListaID, VaraID, Antal, Kommentar, Status, BockadMs, Tillagd) VALUES (@l_grill, @v35, '', '', 'kvar', NULL, DATE_SUB(NOW(), INTERVAL 2880 MINUTE));
