# CCM — Car Cost Management

Responzivní web pro evidenci provozu auta: tankování, nabíjení a ostatní výdaje. Laravel 13 / PHP 8.4, React 19 / TypeScript, externí MariaDB, Docker Compose. Údaje jednotlivých vlastníků jsou oddělené.

## Aktuální stav

První MVP je nasazené a uživatelem ověřené: založení účtu, přihlášení, přidání vozidla a ruční zadávání tankování i výdajů. Opravena je také kompatibilita identifikátorů požadavků s dočasným provozem přes HTTP.

Nově je dostupná **Moje garáž**: více vozidel, úprava údajů, archivace a obnovení, vlastní obrázek / avatar. Obrázky jsou neveřejné a uložené v trvalém Docker volume.

Dokončené jsou **kroky 7 a 8: CSV náhled, kontrola duplicit, potvrzení do historie a vrácení dávky**. Nahrání souboru samo nic nezapisuje; zápis vyžaduje kontrolu shod a výslovné potvrzení. Vrácení chrání později upravené události. Další bod je **CSV export (8a)**.

## Roadmapa

| Stav | Funkcionalita | Poznámka / další práce |
|---|---|---|
| Hotovo | Přihlášení a účty zakládané správcem | Webová session, CSRF a kontrola vlastnictví dat |
| Hotovo | Databázový základ a počáteční číselníky | Přesné desetinné typy, FK, transakce, audit a nové tabulky náhledů |
| Hotovo | Moje garáž a správa vozidel | Seznam, založení, úprava údajů, archivace a obnovení; revize a audit změn |
| Hotovo | Obrázek / avatar vozidla | Nahrání, nahrazení a odstranění; JPEG/PNG/WebP, převod a zmenšení, přístup pouze vlastníkovi |
| Hotovo – základ | Výchozí médium vozidla | Správa dalších nádrží a médií zůstává ve frontě |
| Hotovo – základ | Ruční zadání a editace tankování a výdajů | Revize proti přepsání změn, ochrana proti opakovanému odeslání; pokročilá tankovací pole a vyřazení/obnova ještě nejsou ve formulářích |
| Hotovo | Stránkovaná historie s filtry | Období od–do včetně hranic, měna, kategorie výdaje nebo druh paliva |
| Hotovo | Součty podle měny a druhu události | Stejné filtry jako historie; záporné vratky se započítají jednou |
| Hotovo | Responzivní web a provoz přes HTTP/HTTPS | UUID v4 funguje i bez crypto.randomUUID; pro produkci je cílovým režimem HTTPS |
| Hotovo | Docker image, Compose, deploy a CI | Externí DB_URL, řízené migrace, healthcheck, volitelný oddělený migrační účet |
| Hotovo – krok 7 | CSV validace a náhled SpritMonitor/CCM | UTF-8/BOM, středník, desetinná čárka/tečka, víceřádkové poznámky, dva styly uvozovek, kódy i textové kategorie |
| Hotovo – krok 7 | Uložené dávky, chyby a varování | Původní CSV i řádky se uchovávají; BC nuly podle profilu, SLO→SI, Roads, přesné součty, přeskočení a zrušení náhledu |
| Hotovo – krok 8 | Potvrzení importu do historie | Opětovná validace, kontrola duplicit, výslovné zachování shod, atomický a idempotentní zápis, původ a audit |
| Hotovo – krok 8 | Vrácení dávky | Atomické vyřazení nezměněných událostí, přehled konfliktů a výslovné ponechání pozdějších úprav |
| **Další – krok 8a** | **CSV export** | Stejná struktura 22/7 sloupců; ověření zpětného přenosu |
| Ve frontě – krok 9 | Výpočty spotřeby | Plná–plná, částečná/první/neplatná tankování, přerušení historie, více médií a vážené průměry |
| Ve frontě – krok 10 | Plány opakovaných výdajů | Zatím se ukládá jen interval u skutečného výdaje, nikoli budoucí platby |
| Ve frontě – krok 11 | Analýzy, grafy a náklady na vzdálenost | Kategorie a skupiny nákladů, doložené období/vzdálenost, rozdíl nákupu a spotřeby |
| Ve frontě – dokončení kroků 3–6 | Správa historie a vozidel | Další média, vyřazení/obnova záznamů, pokročilá pole a zobrazení auditu |
| Ve frontě – technické dokončení | Mobilní API a provozní ověření | Úplný OpenAPI kontrakt, tokeny zařízení, další statická analýza, cílová MariaDB LTS a finální přejímka |
| Později | Rozšíření | Nativní iOS/Android klienti, účtenky, sdílení vozidel, notifikace, kurzy a přímá synchronizace |

## CSV import v této verzi

V záložce **CSV import** vyberte vozidlo, typ souboru, profil, desetinný oddělovač a u tankování odpovídající médium. Jednotky je nutné potvrdit: v CSV samotném nejsou uvedené. Jedna dávka má nejvýše **2 MiB a 5 000 záznamů**; náhled je stránkovaný po 25 záznamech.

Chyby se neopravují odhadem. Lze záznam přeskočit, zahrnout zpět nebo nahrát opravený soubor s novým náhledem. Původní data zůstávají zachovaná. Součty zahrnují pouze záznamy bez chyb, které nejsou přeskočené, a nikdy nesčítají různé měny. Kandidáti duplicit se kontrolují uvnitř souboru i v aktuální historii vozidla. Každou shodu je třeba přeskočit nebo vědomě zachovat. Zrušený náhled není fyzicky smazaný.

Současná omezení: bez převodu jednotek, jeden zvolený proud média na soubor, bez ruční editace buněk uloženého náhledu. Náhled se zapisuje až tlačítkem Potvrdit import do historie. Potvrzenou dávku lze vrátit; změněné záznamy se bez rozhodnutí nevyřadí. Podrobný [návod importu a vrácení](docs/program/csv-import.md).

## Spuštění a aktualizace

[Spuštění, konfigurace a Docker deploy](docs/program/spusteni.md). Připojení přes DB_URL v chráněném env souboru mimo repozitář; produkční Compose nezakládá DB server. Tato verze přidává migraci `2026_10_21_000003_complete_csv_imports` (potvrzení/vrácení dávek, vazby na historii a audit importu). Deploy skript provede chybějící migrace před startem nové aplikace. Zálohujte DB i volume `ccm_storage`, ve kterém jsou nyní také obrázky vozidel.

## Ověření

- `npm run test:client` — klientské regresní testy UUID, včetně HTTP fallbacku.
- `npm run build` — TypeScript a produkční sestavení webu.
- `vendor/bin/pint --test` — PHP styl.
- `vendor/bin/phpunit` — oprávnění, transakce, idempotence, revize, garáž, soukromé obrázky, filtry, CSV import/vrácení, souběžná potvrzení a dávku 5 000 řádků; integrační běh proti MariaDB 10.5.28.

Testy používají výhradně samostatnou testovací DB (RefreshDatabase data obnovuje). Soukromé CSV přílohy nejsou součástí repozitáře; volitelné CCM_EXAMPLE_FILES umožní ověřit skutečné příklady mimo Git. Kontrolní základ: 17 tankování / 35 016,60 CZK + 236,43 EUR a 24 výdajů / 560 093,94 CZK. Běžné testy a CI používají syntetická data.

## Dokumentace

- [Cílový koncept](docs/cilovy-koncept.md)
- [Postup implementace a milníky](docs/postup-implementace.md)
- [Technická koncepce](docs/technicka-koncepce.md)
- [Dokumentace programu](docs/program/README.md)
- [Moje garáž a obrázky vozidel](docs/program/moje-garaz.md)
- [Případy použití](docs/program/pripady-pouziti.md)
- [Import, duplicity a vrácení dávky](docs/program/csv-import.md)
- [CSV formát pro import a export](docs/program/csv-import-export.md)
