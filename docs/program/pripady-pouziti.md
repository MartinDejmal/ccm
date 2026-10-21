# Případy použití aplikace CCM

Stav: funkční specifikace plánovaného programu, 20. 10. 2026. Sedm hlavních případů použití vychází ze zadání uživatele. Podrobnosti navazují na [cílový koncept](../cilovy-koncept.md); neznamenají zahájení implementace.

## Přehled

| ID | Případ použití | Kroky implementačního plánu |
|---|---|---|
| UC-01 | Zadání nového tankování | 5 |
| UC-02 | Zadání nového výdaje | 4; plán opakování v 10 |
| UC-03 | Prohlížení a editace historie tankování | 5, 6; přepočty v 9 |
| UC-04 | Analýza a statistiky tankování | 9, 11 |
| UC-05 | Prohlížení a editace historie výdajů | 4, 6 |
| UC-06 | Analýza a statistiky výdajů | 6, 11 |
| UC-07 | Přidání nového vozidla | 3 |

Aktérem je přihlášený vlastník vozidla. Před prací s tankováním, výdaji nebo statistikami má zvolené vlastní vozidlo. Pokud dosud žádné nemá, aplikace nabídne jeho přidání (UC-07). Aktivní vozidlo je vždy viditelné a změna výběru nesmí nepozorovaně přesunout rozpracovaný záznam k jinému vozidlu.

Společná pravidla: chyby se zobrazují u konkrétních polí, rozpracované údaje při neúspěšném uložení zůstávají vyplněné a zrušení formuláře nevytváří záznam. Varování se odlišují od chyb bránících zápisu. Všechny cesty čtení i změn ověřují vlastnictví. Uložení nesmí vytvářet neúplnou událost ani dvě události kvůli opakovanému odeslání stejného požadavku.

## UC-01 — Zadání nového tankování

**Cíl:** Zaznamenat nákup paliva nebo energie ke správnému vozidlu a umožnit jeho zahrnutí do historie, nákladů a případně výpočtu spotřeby.

**Předpoklady:** Existuje aktivní vozidlo a odpovídající proud média (například naftová nádrž nebo elektrická energie) s určenými jednotkami.

**Hlavní průběh:**

1. Uživatel otevře nové tankování pro zvolené vozidlo.
2. Zadá datum, množství, celkovou cenu, měnu, palivo/proud média a typ tankování. Doplní stav tachometru pro výpočet spotřeby. Měna a jednotky se nabídnou podle nastavení vozidla a média, zvolený typ tankování je ve formuláři zřetelný.
3. Volitelně vyplní Trip, poznámku, původní či palubní údaje, pneumatiky, silnice, styl jízdy a místo včetně souřadnic.
4. Aplikace ověří hodnoty a upozorní na nesrovnalosti historie nebo možné duplicitní zadání.
5. Uživatel uloží záznam a zobrazí se jeho detail v historii tankování.

**Výsledek:** Vznikne jedno tankování s jedním finančním záznamem a dohledatelným původem. Dotčené přehledy a výpočty se aktualizují.

**Odchylky a ověření:** Množství musí být kladné, jednotka musí odpovídat médiu, měna a typ musí být platné. Nulová cena je přípustná. Chybějící tachometr se oznámí jako překážka výpočtu, nikoli jako nulová vzdálenost. První, částečné a neplatné tankování mají odlišný vliv na výpočet; uživatel vidí důvod případně nedostupné spotřeby. Souřadnice se zadávají obě, nebo žádná. AdBlue ani elektřina se nesčítají se spotřebou nafty. Poznámka sama nevytváří další nákup.

## UC-02 — Zadání nového výdaje

**Cíl:** Zaznamenat jiný náklad než tankování, případně vratku či příjem vztahující se k vozidlu.

**Předpoklady:** Existuje aktivní vozidlo a dostupné kategorie výdajů.

**Hlavní průběh:**

1. Uživatel otevře nový výdaj pro zvolené vozidlo.
2. Zadá datum, kategorii, částku a měnu.
3. Volitelně doplní tachometr, deklarovanou vzdálenost, poznámku a interval opakování.
4. Pokud chce sledovat příští termín, samostatně zvolí vytvoření plánu opakování a ověří nabízený termín.
5. Po kontrole uloží záznam a zobrazí se jeho detail v historii výdajů.

**Výsledek:** Vznikne jeden skutečný výdaj nebo finanční korekce. Případný plán je veden odděleně a nezvyšuje zaplacené náklady.

**Odchylky a ověření:** Záporná částka je přípustná i v kategorii Insurance; sníží náklady této kategorie. Kategorie neobrací znaménko automaticky. Kladná hodnota u Refund nebo GHG bonus vyvolá varování k ověření. Chybějící tachometr není chyba. Interval může být 0, 1, 3, 6, 12, 24 nebo 36 měsíců. Samotné vyplnění intervalu nevytváří plán ani budoucí platby. Palivo se nezadává znovu jako další výdaj k již evidovanému tankování.

## UC-03 — Prohlížení a editace historie tankování

**Cíl:** Najít dřívější tankování, prohlédnout jeho údaje a opravit je při zachování historie změn.

**Předpoklady:** Je vybrané vlastní vozidlo; prázdná historie je platný stav.

**Hlavní průběh:**

1. Uživatel otevře historii tankování řazenou od nejnovějších záznamů.
2. Zvolí období a případně filtr paliva/proudu média, typu tankování nebo měny.
3. Otevře detail s množstvím, cenou, stavem tachometru, jednotkami a ostatními zadanými údaji. U importu vidí původ záznamu; deklarovaná, palubní a vypočtená spotřeba jsou rozlišené.
4. Spustí editaci, upraví údaje a uloží změnu po kontrole chyb a varování.
5. Aplikace zobrazí aktualizovaný detail a přepočítá ovlivněné intervaly spotřeby i finanční přehledy, včetně navazujících tankování.

**Výsledek:** Oprava je uložená s historií původních a nových hodnot. Původní importní podklad zůstane zachován, aktuální záznam je vůči němu rozlišitelný.

**Odchylky a ověření:** Při souběžné změně aplikace oznámí konflikt a nepřepíše tiše novější údaje. Oprava data nebo pořadí může změnit výpočet více intervalů. Nejasné pořadí tankování stejného dne je potřeba vyřešit před platným výpočtem. Vyřazení záznamu je samostatná vědomá akce; lze jej obnovit. Vyřazené tankování se nezahrnuje do součtů a jeho vyřazení nesmí vytvořit zdánlivě úplný interval přes chybějící nákup.

## UC-04 — Analýza a statistiky tankování

**Cíl:** Zjistit vývoj spotřeby, nakoupené množství a výdaje za palivo či energii v daném období.

**Předpoklady:** Je vybrané vlastní vozidlo. Pro výpočet spotřeby existují úplné a srovnatelné intervaly; nákupy lze zobrazit i bez nich.

**Hlavní průběh:**

1. Uživatel otevře statistiky tankování a zvolí období a proud média.
2. Zobrazí přehled nákupů: počet tankování, celkové množství po médiích a jednotkách a výdaje po měnách.
3. Prohlédne vývoj spotřeby a vážený průměr za úplné intervaly. Přehled uvede skutečné hranice použitých intervalů; interval přesahující zvolenou hranici období se nesmí tvářit jako přesná spotřeba pouze uvnitř období.
4. Otevře podklad ukazatele nebo bodu grafu a zobrazí zúčastněná tankování, vzdálenost, množství a případná omezení výpočtu.

**Výsledek:** Uživatel získá přehled podložený konkrétními záznamy a vidí rozdíl mezi nákupem v období a spotřebou mezi úplnými tankováními.

**Odchylky a ověření:** Průměr spotřeby se váží vzdáleností. První, částečná a neplatná tankování respektují výpočetní pravidla konceptu. Nedostatek dat se zobrazí jako nedostupný výsledek s důvodem, ne jako nula. U elektřiny se bez potřebných údajů zobrazuje evidence dodané energie, nikoli tvrzení o skutečné spotřebě baterie. Litry, kg, kWh ani různé měny se nesčítají do jedné hodnoty. AdBlue má samostatný přehled.

## UC-05 — Prohlížení a editace historie výdajů

**Cíl:** Vyhledat a opravit dřívější náklad nebo vratku a dohledat jejich původ.

**Předpoklady:** Je vybrané vlastní vozidlo; prázdná historie je platný stav.

**Hlavní průběh:**

1. Uživatel otevře historii výdajů řazenou od nejnovějších záznamů.
2. Vyfiltruje období, kategorii, měnu nebo skupinu nákladů (provoz, pořízení, financování, korekce).
3. Otevře detail s částkou, poznámkou, volitelným tachometrem, intervalem opakování a původem záznamu.
4. Upraví údaje a uloží změnu po kontrole.
5. Aplikace aktualizuje historii a finanční přehledy. Existuje-li navázaný plán, jeho změnu řeší odděleně a zobrazí, zda zůstává beze změny, nebo jej uživatel výslovně aktualizuje.

**Výsledek:** Historický výdaj je opraven a změna je dohledatelná. Úprava skutečné platby sama nezaloží další platbu ani tiše nepřepíše plán.

**Odchylky a ověření:** Současné úpravy vyvolají konflikt místo ztráty změn. Vrácená částka si zachová znaménko a kategorii. Vyřazení a obnovení jsou samostatné akce s přepočtem součtů; u navázaného plánu je třeba vyřešit jeho další platnost. Úprava importovaného výdaje nepřepisuje původní podklad a je zohledněna při pozdějším pokusu vrátit importní dávku.

## UC-06 — Analýza a statistiky výdajů

**Cíl:** Zjistit, za co a v jakém období uživatel na vozidlo vynakládá peníze, a oddělit skutečné platby od plánů.

**Předpoklady:** Je vybrané vlastní vozidlo. Náklady na vzdálenost navíc vyžadují spolehlivé odečty tachometru.

**Hlavní průběh:**

1. Uživatel otevře statistiky výdajů a vybere období, měnu, kategorie a skupiny nákladů.
2. Zobrazí kladné výdaje, záporné vratky/příjmy a čistý součet, členění podle kategorií a vývoj v čase.
3. Podle potřeby zahrne pořízení či financování. Pokud přehled nabízí celkové náklady včetně tankování, tato volba je výslovná a nákupy paliva jsou uvedeny samostatně.
4. Pokud jsou k dispozici odpovídající odečty, zobrazí náklady na kilometr/míli spolu s použitou vzdáleností a skutečným pokrytým obdobím.
5. Otevře podklad součtu nebo grafu jako seznam konkrétních výdajů.

**Výsledek:** Každý součet odpovídá zvoleným filtrům a lze dohledat jeho zdrojové záznamy.

**Odchylky a ověření:** Měny se nepřevádějí ani nesčítají bez kurzového mechanismu. Vratka sníží náklady právě jednou. Pořízení a financování mají vlastní mezisoučty; součet pořizovací ceny a splátek se nevydává bez vysvětlení za ekonomické celkové náklady. Tankování se nezapočítá podruhé. Plánované platby se do skutečných výdajů nezahrnují. Bez prokazatelné vzdálenosti není metrika nákladů na kilometr/míli dostupná; aktuální celkový stav tachometru není vzdálenost za období.

## UC-07 — Přidání nového vozidla

Aktuální implementace a rozšířená správa vozidel (úpravy, archivace, obnovení a obrázek) jsou popsány v [návodu Moje garáž](moje-garaz.md). Následující cílový průběh zahrnuje i dosud plánovanou správu více médií.

**Cíl:** Založit vozidlo připravené pro evidenci tankování a ostatních výdajů.

**Předpoklady:** Uživatel je přihlášený; předchozí vozidlo není potřeba.

**Hlavní průběh:**

1. Uživatel otevře přidání vozidla ze seznamu vozidel nebo z prázdného úvodního přehledu.
2. Zadá název, zvolí jednotku vzdálenosti a výchozí měnu.
3. Volitelně doplní značku, model, VIN, registrační značku, datum pořízení a počáteční stav tachometru.
4. Pro evidenci tankování nastaví používané médium/nádrž, jednotku množství a metodu evidence spotřeby; lze přidat více proudů například pro kombinovaný pohon. Pro samotnou evidenci ostatních výdajů může nastavení média odložit.
5. Potvrdí založení. Vozidlo se přiřadí přihlášenému vlastníkovi, objeví se v seznamu a lze je vybrat pro další evidenci.

**Výsledek:** Existuje nové vozidlo s vlastní historií a nastavením. Pokud je nastaveno médium, lze pokračovat UC-01; ostatní výdaje lze evidovat ihned.

**Odchylky a ověření:** Název, měna a jednotka vzdálenosti jsou povinné. Počáteční tachometr nesmí být záporný a není automaticky ujetou vzdáleností za dobu vlastnictví. VIN ani registrační značka nejsou povinné a nezakládají automatické propojení s jiným vozidlem. Bez vhodného média aplikace při zahájení tankování nabídne jeho nastavení. Přidání vozidla nevytváří automaticky výdaj za jeho pořízení; ten se zadává samostatně v UC-02.

## Vazba na import a export

Import je další způsob naplnění stejných historií; importované záznamy podléhají stejným pravidlům prohlížení, editace a statistik jako ručně zadané. Zpracování dávky má vlastní postup v krocích 7 a 8 [implementačního plánu](../postup-implementace.md). Podobu souborů pro import i export určuje [specifikace CSV](csv-import-export.md). Tyto pomocné postupy nenahrazují sedm výše uvedených hlavních případů použití.
