# CCM — postup implementace funkcionality

Stav: plán, 20. 10. 2026. Vychází z [cílového konceptu](cilovy-koncept.md), který určuje datový model, číselníky a obchodní pravidla. Tento dokument pouze rozděluje budoucí práci; nezahajuje implementaci. Doporučené technologie a provozní řešení nově určuje [technická koncepce](technicka-koncepce.md).

Funkční zadání jednotlivých kroků doplňují [případy použití UC-01 až UC-07](program/pripady-pouziti.md), včetně mapování na implementační kroky.

## Princip postupu

Postupovat po malých funkčních celcích od vlastnictví a ruční evidence přes import až k výpočtům a přehledům. Každý krok zahrnuje potřebná data, pravidla, uživatelské ovládání a odpovídající ověření. Import využívá stejná pravidla zápisu jako ruční evidence. Oprávnění, audit a ochrana integrity se zavádějí od začátku.

Čísla kroků určují doporučené pořadí. Závislosti uvádějí nezbytné dokončené předpoklady. Odhady termínů a pracnosti se stanoví podle technické koncepce a dostupné kapacity.

## 1. Uzavřít funkční pravidla a přijímací scénáře

**Rozsah:** Zpřesnit význam zdrojových kategorií 7 (Supervisory board) a 13 (Payment), pravidlo nulových BC údajů, toleranci rozdílu Trip a tachometru, pořadí událostí stejného dne a chování při neúplné historii. Definovat, jak uživatel označí přerušení historie a případně potvrdí plnou nádrž u prvního tankování. Určit výběr spolehlivých odečtů pro náklady na vzdálenost, včetně rozdílu mezi obdobím výdajů a obdobím dostupných odečtů.

Upřesnit změny jednotek po založení historie: v první verzi preferovat zákaz změny bez samostatného převodu dat. Doplnit způsob výběru proudu média a jednotek při importu smíšených paliv; jedna jednotka na celou dávku není pro takový soubor dostatečná. Vyjasnit posun příštího termínu opakovaného nákladu po potvrzení skutečné platby a chování plánu při vyřazení zdrojového výdaje.

Připravit popisy obrazovek a scénáře běžného zadání, chyb, varování a obnovy. Rozhodnutí promítnout do konceptu a případných doplnění datového modelu ještě před realizací dotčených funkcí.

**Hotovo, když:** Každá uvedená nejasnost má zdokumentované pravidlo a příklad očekávaného výsledku. Existuje seznam přijímacích scénářů odvozený z kapitoly 9 konceptu.

**Závislosti:** Žádné.

## 2. Založit datový a přístupový základ

**Rozsah:** Podle technické koncepce připravit strukturu dat a verzované změny schématu, naplnění všech číselníků a vazeb podle konceptu. Zavést účty, přihlášení a ověřování vlastnictví. Připravit společné zacházení s daty, desetinnými hodnotami, měnami, jednotkami, chybami a technickými časy. Stanovit retenční pravidla pro importní podklady a audit.

**Hotovo, když:** Prázdnou databázi lze opakovatelně připravit do stejného stavu, naplnění číselníků nevytváří duplicity a oddělení dat dvou vlastníků je ověřeno. Neplatné vazby ani nepřípustné kombinace jednotek nelze uložit podporovanými cestami zápisu.

Součástí je kostra Laravel API /api/v1, React/TypeScript klienta, OpenAPI kontraktu, automatických kontrol a Docker sestavení. Web používá sessions, budoucí mobilní klienti samostatné zařízení/tokeny dle technické koncepce.

**Závislosti:** 1; před zahájením se ověří konkrétní verze závislostí a cílová MariaDB. Přechodný provoz na stávající 10.5.28 vyžaduje samostatné integrační ověření.

## 3. Správa vozidel a používaných médií

**Rozsah:** Seznam, založení, detail, úprava a archivace vozidla. Výchozí měna, jednotka vzdálenosti, počáteční tachometr a datum pořízení. Nastavení nádrží/proudů média, paliv a jednotek. Oddělit archivaci od vratného vyřazení a popsat vliv obou stavů na výběry a historii.

**Hotovo, když:** Jeden vlastník může spravovat více vozidel. Archivace zachová historii, výběr vozidla určuje kontext další práce a nastavení nelze změnit způsobem, který by přepsal význam uložených hodnot.

**Stav 21. 10. 2026:** Implementována Moje garáž: seznam a filtry aktivních/archivovaných vozidel, založení, úprava údajů, archivace a obnovení s revizí a auditem. Přidány neveřejné obrázky vozidel s převodem a limity. Jednotku vzdálenosti nelze měnit po vzniku historie či CSV náhledu. Správa dalších proudů média a samostatné vyřazování zůstávají ve frontě. Viz [návod](program/moje-garaz.md).

**Závislosti:** 2.

## 4. Společný základ událostí a ostatní výdaje

**Rozsah:** Zavést atomický zápis společné události a jejího detailu, historii změn, ochranu souběžných úprav, vratné vyřazení a obnovení. Nad tím vytvořit ruční evidenci ostatních nákladů: datum, volitelný tachometr/Trip, kategorie, částka, měna, poznámka a interval opakování. Zatím pouze uchovat interval, bez vytváření plánů.

**Hotovo, když:** Lze vytvořit, upravit, vyřadit a obnovit výdaj včetně záporné vratky. Neúspěšný zápis nezanechá neúplnou událost, souběžná úprava neztratí změny a audit zachytí původní i nové hodnoty. Opakování samo nezakládá budoucí výdaje.

**Závislosti:** 3.

## 5. Ruční evidence tankování a nabíjení

**Rozsah:** Doplnit tankovací detail ke společné události: množství, jednotka, palivo, proud média, typ tankování, Trip, původní a BC spotřeba, podmínky jízdy a místo. Zpřístupnit opravu pořadí ve stejný den a pravidla přerušení historie stanovená v kroku 1. Pokročilá nepovinná pole ponechat mimo hlavní část formuláře.

**Hotovo, když:** Lze evidovat první, plné, částečné a neplatné tankování, elektřinu i AdBlue s odpovídajícími jednotkami. Zápis ceny vytvoří právě jeden finanční záznam. Kontrola odliší chybu od varování a neodmítne automaticky servisní nesrovnalost tachometru.

**Závislosti:** 4.

## 6. Společná historie a základní finanční přehled

**Rozsah:** Časová osa a seznamy s filtrem vozidla, období, druhu události, kategorie a měny. Detaily včetně původu a historie změn. Zobrazit kladné výdaje, záporné korekce a čistý součet samostatně po měnách; oddělit provoz, pořízení a financování. Bez závislosti na výpočtu spotřeby.

**Hotovo, když:** Ručně zadané údaje se správně zobrazí a sečtou, vyřazené záznamy se nezapočítávají a tankování se nezapočítá dvakrát. Uživatel vidí aktivní filtry a rozsah každého součtu.

**Závislosti:** 4, 5.

**Milník A:** Použitelná ruční evidence vozidel, tankování a nákladů se základní kontrolou financí.

## 7. Načtení CSV a náhled importu

**Stav 21. 10. 2026:** Implementována validace a uložený stránkovaný náhled, profily, chybové/varovné řádky, jejich přeskočení/obnovení a zrušení dávky. Limity 2 MiB / 5 000 záznamů. Bez zápisu událostí; navazuje krok 8. Oprava interpretace probíhá novým nahráním se správným profilem či jednotkami; editace jednotlivých buněk není součástí aktuální verze. Přesný přehled dokončených a zbývajících částí je v roadmapě README.

**Specifikace formátu:** [CSV soubory pro import a export](program/csv-import-export.md). Import přijímá popsané varianty; export bude používat stejnou základní strukturu. Realizace exportu není součástí tohoto kroku.

**Rozsah:** Výběr vozidla, typu exportu, profilu a jednotek. Zpracování hlaviček a jejich aliasů, desetinné čárky, dat, víceřádkových poznámek a escapování. Mapování číselných i textových kategorií, Roads, SLO a nulových BC údajů podle profilu. Uchovat původní záznamy a připravit přehled chyb a varování s odkazem na konkrétní pole. Umožnit opravu interpretace či přeskočení záznamu bez změny originálu.

V této etapě import vytváří pouze pracovní dávku a náhled, nikoli události v historii vozidla. Doplnit limity velikosti souboru a počtu záznamů a srozumitelné chování při jejich překročení.

**Hotovo, když:** Dodané přílohy dávají 17 tankování a 24 nákladů se správnými částkami, poznámkami a původními hodnotami. Poškozené CSV a neznámé kódy mají konkrétní vysvětlení. Náhled neovlivní finanční přehledy. Volba profilu rozliší zdrojové nuly BC od skutečných nul vlastního formátu CCM.

**Závislosti:** 5, 6.

## 8. Potvrzení importu, duplicity a vrácení dávky

**Rozsah:** Vyhledat kandidáty duplicit v dávce i historii daného vozidla. Nabídnout přeskočení nebo výslovné zachování shodné události. Přidat atomické potvrzení vyřešené dávky, ochranu před opakovaným a souběžným potvrzením, souhrn a historii importů. Doplnit vrácení dávky s ochranou později upravených událostí a zachováním auditu. Před zápisem znovu ověřit oprávnění a platnost náhledu vůči aktuálním datům.

**Hotovo, když:** Opakované potvrzení nevytvoří další události a chyba uprostřed zápisu nezanechá částečný import. Dva skutečně shodné výdaje lze vědomě zachovat. Vrácení dávky nezasáhne předchozí záznamy ani bez řešení nevyřadí později upravené události.

Kontrolní součty příloh po importu: tankování **35 016,60 CZK + 236,43 EUR**, ostatní výdaje **560 093,94 CZK**. Měny se nesčítají dohromady.

**Závislosti:** 7.

**Stav 21. 10. 2026:** Implementováno potvrzení do historie, kandidáti duplicit se souhlasem pro konkrétní shody, idempotence, serializace souběžných zápisů, audit a vrácení dávky s přehledem konfliktů. Změněné události lze výslovně ponechat; násilné vyřazení upravených záznamů se nenabízí. Ověřeno i pro limit 5 000 řádků a původní přílohy. Viz [návod](program/csv-import.md).

**Milník B:** Bezpečně použitelný import obou exportů SpritMonitor.de včetně náhledu, duplicit a možnosti návratu.

## 8a. CSV export ve společném formátu

**Rozsah:** Export tankování a ostatních nákladů podle [specifikace CSV](program/csv-import-export.md), se stejným oprávněním a filtry jako historie. Generování probíhá na serveru, jednotky jsou uvedené uživateli a u nejednoznačných médií vznikají oddělené soubory.

**Hotovo, když:** Export má 22/7 sloupců a zpětný import do prázdného vozidla se stejnými jednotkami zachová přenášené hodnoty včetně poznámek, přesnosti, znamének a skutečných nul BC při profilu CCM. Omezení přenosu interních údajů jsou zřetelná.

**Závislosti:** 8.

## 9. Výpočet spotřeby a kvalita historie

**Rozsah:** Výpočet plná–plná po jednotlivých proudech média, částečná tankování, vážený průměr, přerušení řetězce a neúplné intervaly. Rozlišit vypočtené, importované a palubní údaje. U elektřiny a dalších dodávek používat pouze ukazatele podložené dostupnými daty. Výsledek vysvětlit konkrétními hranicemi intervalu, množstvím a vzdáleností.

Změna data, tachometru, pořadí, typu, množství či média, stejně jako import, vyřazení, obnova a vrácení dávky musí aktualizovat všechny ovlivněné intervaly. Nespoléhat na jednorázový výpočet při vytvoření záznamu.

**Hotovo, když:** Ověřovací příklady pokrývají plné a částečné tankování, první a neplatný záznam, výpadek historie, nulovou vzdálenost, nejasné pořadí, zpětnou opravu i více médií. Litry, kilogramy a kWh se nemíchají a AdBlue nemění spotřebu nafty.

**Stav 22. 10. 2026:** Implementovány intervaly a vážené průměry, částečná/první/neplatná tankování, explicitní pořadí a mezery, podklady a vysvětlení, ochrana před nejasnými odečty i oddělené dodávky elektřiny/AdBlue. Přepočet při čtení reaguje na opravy, import a vrácení. CSV export 8a zůstává ve frontě; krok 9 byl upřednostněn na výslovné přání uživatele. Viz [návod](program/spotreba.md).

**Závislosti:** 5, 8.

## 10. Plánování opakovaných výdajů

**Implementováno 22. 10. 2026:** výslovné založení i z importu, kotevní kalendář, editace/ukončení, atomický zápis platby a posun jednoho termínu, audit a ukončení při vrácení zdroje. Podrobnosti v [návodu](program/opakovane-vydaje.md).

**Rozsah:** Vytvoření, úprava, ukončení a přehled plánu odvozeného od skutečného výdaje. Výpočet dalšího termínu podle původního kotevního dne včetně kratších měsíců a přestupného roku. Při importu nabídnout samostatnou volbu vytvořit plán, předem nezvolenou. Propojit zapsání skutečné platby s posunem plánu podle pravidel z kroku 1.

**Hotovo, když:** Intervaly 1, 3, 6, 12, 24 a 36 měsíců dávají správné termíny. Historický import nevytvoří automaticky několik plánů téhož pojištění. Plán ani odhadovaná částka nezvyšují skutečně zaplacené náklady. Vyřazení nebo vrácení zdrojové události nezanechá nevysvětlený aktivní plán.

**Závislosti:** 4, 8.

## 11. Souhrnný přehled, grafy a náklady na vzdálenost

**Rozsah:** Propojit finanční přehled, vývoj spotřeby, členění nákladů a plánované termíny. Doplnit náklady na kilometr/míli jen pro doloženou vzdálenost a jasně uvedené období. Zobrazit nedostatek dat, varování a vysvětlení použitých hodnot. Zachovat oddělení měn a médií.

**Hotovo, když:** Každý ukazatel lze dohledat na zdrojové události a filtry odpovídají zobrazeným součtům. Chybějící data se nezobrazují jako nulová spotřeba či nulové náklady na kilometr. Pořizovací cena a splátky jsou rozeznatelné od provozních výdajů.

**Závislosti:** 6, 9, 10.

**Milník C:** Funkčně úplná první verze podle cílového konceptu.

## 12. Ověření celé první verze a příprava provozu

**Rozsah:** Projít úplný scénář od nového účtu přes vozidlo, ruční evidenci a oba importy až po reporty, opravy a vrácení dávky. Dokončit ověření oddělení vlastníků, souběhu změn, transakcí a obnovy po chybě. Vyzkoušet přiměřeně rozsáhlou historii, stránkování a rychlost přehledů. Ověřit zálohování a obnovu dat včetně auditu a původu importu. Připravit stručný návod a seznam známých omezení. Realizovat a vyzkoušet deploy skript podle technické koncepce: stažení konkrétního release, build image, připojení externí MariaDB, řízené migrace, kontrolu připravenosti a návrat kompatibilního release.

**Hotovo, když:** Jsou splněna přijímací kritéria konceptu i jednotlivých kroků, kontrolní součty souhlasí a obnova byla skutečně vyzkoušena. Produkční data se nenačítají jen pro účely testování. Samotné nasazení je samostatná navazující činnost.

**Závislosti:** 11, 8a.

## Organizace navazující práce

Každý krok rozdělit na menší úkoly s konkrétním výsledkem a ověřením. Dokončení funkce zahrnuje přístupová pravidla, validaci, chybové i prázdné stavy a aktualizaci dokumentace. Testovací scénáře vznikají spolu s funkcemi, ne až v závěrečném kroku. Dodané CSV používat jako kontrolní podklady, nikoli jako automaticky zveřejněná demonstrační data; veřejné ukázky mají být anonymizované nebo syntetické.

První pracovní zadání po zahájení vývoje má být krok 1 a následné ověření a založení technického řešení podle technické koncepce v kroku 2. Sdílení vozidel, účtenky, notifikace, automatické kurzy a přímá synchronizace zůstávají mimo první verzi.
