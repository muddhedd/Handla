# Handla

*[English version](README.en.md)*

Handlingslistor som lär sig hur du handlar. Verktyget är gjort i PHP och MySQL/MariaDB och byggt för mobilen i butiken. Det fungerar lika bra på datorn när du planerar. Det kräver inga ramverk och ingen byggprocess.

## Funktioner

**Listor**
- Återanvändbara listor kopplade till en butik, till exempel *Veckohandling* på *Willys*.
- Lägg till varor med förslag från varuregistret medan du skriver. Varje rad kan ha antal och kommentar, som "2 st" eller "laktosfri".
- **Tillfälliga listor** för engångstillfällen, som en grillfest. De påverkar aldrig statistiken.

**Börja handla**
- Varje vara blir en stor ruta att bocka av. Avbockade varor hamnar överstrukna sist i listan, och ett tryck till ångrar.
- **Fanns inte**: varan ligger kvar till nästa gång utan att påverka statistiken.
- Du kan byta butik för just den här gången och lägga till varor medan du handlar.
- Skärmen släcks inte medan du handlar (Wake Lock).
- **Klar**: kostnaden är frivillig. Avbockade varor försvinner från listan och resten ligger kvar. Råkar du trycka Klar för tidigt kan du ångra.

**Det verktyget lär sig**

Allt bygger på dina tidigare handlingar från listor som inte är tillfälliga:
- **Plockordning per butik**: varorna sorteras i den ordning du brukar bocka av dem. Nya varor sorteras efter sin avdelning, till exempel "Kyl", tills ordningen är inlärd.
- **När du köpte varan senast**, **hur ofta du brukar köpa den** och **i vilka butiker**.
- **Förslag** när du planerar en lista:
  - *Dags att köpa*: varor du köper regelbundet i butiken och som snart är på tur.
  - *Sällanköp*: varor du köper mer sällan än var fjärde vecka, med ditt vanliga intervall.
  - *Annat du brukar köpa här*.

**Kostnader**
- Per handlingstillfälle, vecka, månad och år, med filter på butik och lista.
- Nyckeltal för året, snitt per vecka, månad och handling, och ett stapeldiagram för de senaste 12 månaderna.
- Kostnaden kan läggas till eller ändras i efterhand.

**Varuregister**
- All statistik per vara.
- Byt namn eller avdelning, slå ihop dubbletter och ta bort varor. Butiker hanteras på samma ställe.

**Flera enheter och dålig täckning**
- **Livesynk**: två mobiler som handlar från samma lista ser varandras avbockningar inom några sekunder.
- **Offline**: utan nät sparas avbockningarna i telefonen och skickas automatiskt när nätet är tillbaka.

## Krav

- PHP 7.4 eller senare (8.x rekommenderas) med `mysqli` (mysqlnd) och `mbstring`
- MySQL 5.7+ eller MariaDB 10.3+, med InnoDB, som kopplingarna mellan tabellerna kräver
- HTTPS rekommenderas, och Wake Lock fungerar bara över HTTPS.

## Installation

1. **Skapa en databas** och kör `sql/schema.sql` i den, till exempel i phpMyAdmin under fliken SQL.
2. **Exempeldata (valfritt):** kör `sql/exempeldata.sql`. Den innehåller tre listor, två butiker och tolv veckors handelshistorik, så att förslag, plockordning och kostnader syns direkt. Den lägger datan på användar-ID 1.
3. **Kopiera** `config.example.php` till `config.php` och fyll i databasuppgifterna och `LOGIN_USER`.
4. **Ladda upp** mappen till din webbserver.
5. **Öppna** `handla.php` i webbläsaren. Första gången visas en guide:
   1. Välj ett lösenord, så skapas en hash.
   2. Klistra in raden i `config.php` som `LOGIN_PASSWORD_HASH` och ladda om sidan.
   3. Logga in.

`config.php` finns i `.gitignore`, så dina lösenord hamnar aldrig i git.

## Inloggningslägen

Inloggningsläget ställs in med `AUTH_MODE` i `config.php`:

| Läge | Beskrivning |
|---|---|
| `login` | Inbyggd inloggning med ett användarnamn och lösenord. Standardläget. |
| `demo` | Ingen inloggning. Varje besökare får en egen sandlåda med exempeldata som raderas efter `DEMO_TTL_HOURS` timmar. Kräver `sql/demo.sql` i stället för `exempeldata.sql`. |
| `ll2` | Delar session med ett befintligt inloggningssystem (`ll2.php`). Används i författarens egen installation och kan ignoreras. |

## Hur statistiken räknas

- **Plockordning:** när du trycker Klar får varje avbockad vara en position mellan 0 (först) och 1 (sist). Varans plats i en butik är snittet av de tio senaste gångerna.
- **Intervall:** genomsnittligt antal dagar mellan de elva senaste köpdagarna. Minst två köp krävs.
- **Dags att köpa:** när det har gått minst 75 % av intervallet sedan förra köpet (85 % för sällanköp), och varan oftast köps i den här butiken eller där minst en fjärdedel av gångerna.
- Gränsen för sällanköp är 28 dagar. Den ändras med konstanten `SALLAN_DAGAR` i `handla.php`.

## Filer

| Fil | Innehåll |
|---|---|
| `handla.php` | Översikt, varuregister, kostnader, statistik och allt som körs på servern |
| `handla-lista.inc.php` | Vyn för en lista: planera, handla, livesynk och offline-kö |
| `common.php` | Gemensamt nav: databas, inloggning, CSRF-skydd, meny och demoläge |
| `config.example.php` | Mall för inställningarna |
| `demo.php` | Kopierar exempeldata till en besökares sandlåda (bara demoläget) |
| `minsida.css` | Utseendet |
| `sql/` | `schema.sql`, `exempeldata.sql` och `demo.sql` |

## Säkerhet

- Alla databasfrågor använder prepared statements.
- Alla ändringar skyddas med en CSRF-token.
- Varje ändring kontrolleras mot inloggad användare.
- Lösenordet lagras som en `password_hash`.

## Licens

MIT – se [LICENSE](LICENSE).
