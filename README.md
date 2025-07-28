# Zsuri Rendszer - WordPress Plugin

Ez a WordPress plugin egy zsűri rendszert biztosít, amely lehetővé teszi a felhasználók számára, hogy szavazzanak különböző kategóriákban.

## Funkciók

### 🎯 Alapvető funkciók
- **Szavazási rendszer**: Felhasználók szavazhatnak különböző kategóriákban
- **Kategória kezelés**: Több szavazási kategória támogatása
- **AJAX szavazás**: Dinamikus szavazás JavaScript segítségével
- **Reszponzív design**: Mobilbarát felület

### 🎨 Testreszabási lehetőségek
- **Stílus testreszabás**: CSS osztályok a megjelenés módosításához
- **JavaScript API**: Programozott hozzáférés a szavazási funkciókhoz
- **Hook rendszer**: WordPress hook-ok a bővítéshez

## Telepítés

### 1. Fájlok feltöltése
1. Töltsd fel a plugin fájljait a WordPress `wp-content/plugins/zsuri-rendszer/` mappába
2. Vagy csomagold be a fájlokat ZIP formátumban és telepítsd a WordPress admin panelen keresztül

### 2. Plugin aktiválása
1. Menj a WordPress admin panelbe
2. Navigálj a **Beépülő modulok** > **Telepített beépülő modulok** menüpontra
3. Keresd meg a "Zsuri Rendszer" beépülő modult
4. Kattints az **Aktiválás** gombra

## Használat

### 1. Shortcode használata
A plugin shortcode-ot biztosít a szavazási rendszer megjelenítéséhez:

```
[zsuri_rendszer]
```

### 2. JavaScript API
```javascript
// Szavazás küldése
zsuriVote(categoryId, optionId);

// Szavazási eredmények lekérése
getVoteResults(categoryId);
```

## CSS osztályok

A plugin a következő CSS osztályokat használja:
- `.zsuri-container`: Fő konténer
- `.zsuri-category`: Kategória konténer
- `.zsuri-option`: Szavazási opció
- `.zsuri-vote-button`: Szavazás gomb
- `.zsuri-results`: Eredmények megjelenítése

## AJAX funkciók

A plugin AJAX segítségével működik:
- **Szavazás**: Dinamikus szavazás oldal újratöltés nélkül
- **Eredmények**: Valós idejű eredmények frissítése
- **Hiba kezelés**: Felhasználóbarát hibaüzenetek

## Biztonság

- **Nonce ellenőrzés**: Minden AJAX kérés nonce ellenőrzéssel védett
- **Felhasználó ellenőrzés**: Csak bejelentkezett felhasználók szavazhatnak
- **Duplikált szavazás védelem**: Egy felhasználó csak egyszer szavazhat kategóriánként

## Hibaelhárítás

### Szavazás nem működik
1. Ellenőrizd, hogy a felhasználó be van-e jelentkezve
2. Nézd meg a böngésző konzolját JavaScript hibákért
3. Ellenőrizd a WordPress debug log-ot

### AJAX hibák
1. Ellenőrizd, hogy az AJAX URL helyes-e
2. Nézd meg a hálózati fülön a kéréseket
3. Ellenőrizd a WordPress permalink beállításokat

## Verzió információk

- **Verzió**: 1.0.0
- **PHP verzió**: 7.0+
- **WordPress verzió**: 5.0+
- **JavaScript**: ES6+ támogatás

## Licenc

Ez a plugin GPL v2 vagy újabb licenc alatt érhető el.

## Támogatás

Ha problémába ütközöl vagy kérdésed van, kérlek hozz létre egy issue-t a GitHub repository-ban.

## Közreműködés

A közreműködéseket szívesen fogadjuk! Kérlek:
1. Fork-old a repository-t
2. Hozz létre egy feature branch-et
3. Commit-old a változtatásaidat
4. Push-old a branch-et
5. Hozz létre egy Pull Request-et

---

**Fejlesztő**: Your Name  
**Utolsó frissítés**: 2024. január 