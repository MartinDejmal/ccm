import React, { useEffect, useState, useRef, FormEvent } from "react";
import { createRoot } from "react-dom/client";
type Row = Record<string, any>;
async function api(path: string, method = "GET", data?: Row) {
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )!.content;
    const res = await fetch("/api/v1" + path, {
        method,
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": token,
        },
        credentials: "same-origin",
        body: data ? JSON.stringify(data) : undefined,
    });
    if (res.status === 204) return null;
    const body = await res.json();
    if (!res.ok)
        throw new Error(
            Object.values(
                body.errors ?? { message: body.message ?? "Požadavek selhal" },
            )
                .flat()
                .join(" "),
        );
    return body;
}
function App() {
    const requestVersion = useRef(0);
    const [user, setUser] = useState<Row | null>(null),
        [checked, setChecked] = useState(false),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    const [vehicles, setVehicles] = useState<Row[]>([]),
        [catalogs, setCatalogs] = useState<Row>({}),
        [vehicle, setVehicle] = useState(""),
        [streams, setStreams] = useState<Row[]>([]),
        [events, setEvents] = useState<Row[]>([]),
        [summary, setSummary] = useState<Row[]>([]);
    const [mode, setMode] = useState("fueling"),
        [form, setForm] = useState<Row | null>(null),
        [editId, setEditId] = useState(""),
        [page, setPage] = useState(1),
        [lastPage, setLastPage] = useState(1);
    useEffect(() => {
        api("/me")
            .then(setUser)
            .catch(() => {})
            .finally(() => setChecked(true));
    }, []);
    useEffect(() => {
        if (user)
            Promise.all([api("/vehicles"), api("/catalogs")])
                .then(([v, c]) => {
                    setVehicles(v);
                    setCatalogs(c);
                    if (v.length) setVehicle(v[0].id);
                })
                .catch((e) => setError(e.message));
    }, [user]);
    async function reload() {
        const version = ++requestVersion.current;
        if (!vehicle || mode === "vehicle") return;
        const [e, s, t] = await Promise.all([
            api(`/vehicles/${vehicle}/events?kind=${mode}&page=${page}`),
            api(`/vehicles/${vehicle}/summary`),
            api(`/vehicles/${vehicle}/streams`),
        ]);
        if (version !== requestVersion.current) return;
        setEvents(e.data);
        setLastPage(e.last_page);
        setSummary(s);
        setStreams(t);
    }
    useEffect(() => {
        setError("");
        reload().catch((e) => setError(e.message));
    }, [vehicle, mode, page]);
    const selected = vehicles.find((v) => v.id === vehicle);
    async function run(action: () => Promise<void>) {
        setError("");
        setBusy(true);
        try {
            await action();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    const money = (s: string) =>
        new Intl.NumberFormat("cs-CZ", { maximumFractionDigits: 2 }).format(
            Number(s),
        );
    function input(
        label: string,
        name: string,
        type = "text",
        required = false,
    ) {
        return (
            <label>
                {label}
                <input
                    name={name}
                    type={type}
                    required={required}
                    value={form?.[name] ?? ""}
                    onChange={(e) =>
                        setForm({ ...form, [name]: e.target.value })
                    }
                    step={type === "number" ? "any" : undefined}
                />
            </label>
        );
    }
    function select(
        label: string,
        name: string,
        rows: Row[],
        key = "id",
        display = "name",
    ) {
        return (
            <label>
                {label}
                <select
                    required
                    value={form?.[name] ?? ""}
                    onChange={(e) =>
                        setForm({ ...form, [name]: e.target.value })
                    }
                >
                    <option value="">Vyberte…</option>
                    {rows.map((r) => (
                        <option key={r[key]} value={r[key]}>
                            {r[display]}
                        </option>
                    ))}
                </select>
            </label>
        );
    }
    function newEntry() {
        setEditId("");
        setForm({
            request_key: crypto.randomUUID(),
            kind: mode,
            occurred_on: new Date().toLocaleDateString("sv-SE"),
            currency_code: selected?.currency_code ?? "CZK",
            fueling_type_id: 1,
            energy_stream_id: streams[0]?.id ?? "",
            fuel_sort_id: "",
            repetition_months: 0,
        });
    }
    async function save(e: FormEvent) {
        e.preventDefault();
        await run(async () => {
            const data = { ...form };
            for (const k of Object.keys(data))
                if (data[k] === "") data[k] = null;
            if (mode === "vehicle") {
                const v = await api("/vehicles", "POST", data);
                setVehicles([...vehicles, v]);
                setMode("fueling");
                setVehicle(v.id);
            } else
                await api(
                    `/vehicles/${vehicle}/events${editId ? "/" + editId : ""}`,
                    editId ? "PUT" : "POST",
                    data,
                );
            setForm(null);
            setEditId("");
            await reload();
        });
    }
    if (!checked) return <main>Načítání…</main>;
    if (!user)
        return (
            <main className="login">
                <span className="badge">CCM / CAR COST MANAGEMENT</span>
                <h1>
                    Vaše auto.
                    <br />
                    Jasné náklady.
                </h1>
                <p>Tankování, servis a výdaje na jednom místě.</p>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        const d = new FormData(e.currentTarget);
                        run(async () => {
                            await api("/login", "POST", Object.fromEntries(d));
                            location.reload();
                        });
                    }}
                >
                    <h2>Přihlášení</h2>
                    <label>
                        E-mail
                        <input
                            name="email"
                            type="email"
                            autoComplete="username"
                            required
                        />
                    </label>
                    <label>
                        Heslo
                        <input
                            name="password"
                            type="password"
                            autoComplete="current-password"
                            required
                        />
                    </label>
                    {error && (
                        <p role="alert" className="error">
                            {error}
                        </p>
                    )}
                    <button disabled={busy}>Přihlásit se</button>
                </form>
            </main>
        );
    return (
        <>
            <header>
                <a href="/" className="logo">
                    ccm<span> / náklady na auto</span>
                </a>
                <button
                    className="secondary"
                    onClick={() =>
                        run(async () => {
                            await api("/logout", "POST");
                            location.reload();
                        })
                    }
                >
                    Odhlásit
                </button>
            </header>
            <main>
                <div className="heading">
                    <div>
                        <span className="badge">VAŠE GARÁŽ</span>
                        <h1>Přehled provozu</h1>
                    </div>
                    <button
                        onClick={() => {
                            setMode("vehicle");
                            setEditId("");
                            setForm({
                                distance_unit: "km",
                                currency_code: "CZK",
                            });
                        }}
                    >
                        + Vozidlo
                    </button>
                </div>
                <label className="vehicle">
                    Vozidlo
                    <select
                        value={vehicle}
                        onChange={(e) => {
                            setVehicle(e.target.value);
                            setForm(null);
                            setPage(1);
                        }}
                    >
                        <option value="">Vyberte vozidlo</option>
                        {vehicles.map((v) => (
                            <option key={v.id} value={v.id}>
                                {v.name}
                            </option>
                        ))}
                    </select>
                </label>
                {error && (
                    <p role="alert" className="error">
                        {error}
                    </p>
                )}
                {selected && (
                    <>
                        <section className="stats">
                            {summary.map((s, i) => (
                                <article key={i}>
                                    <small>
                                        {s.kind === "fueling"
                                            ? "Palivo a energie"
                                            : "Ostatní výdaje"}
                                    </small>
                                    <strong>
                                        {money(s.total)}{" "}
                                        <span>{s.currency_code}</span>
                                    </strong>
                                </article>
                            ))}
                            {!summary.length && (
                                <article>
                                    <small>
                                        Historie tohoto vozidla je zatím
                                        prázdná.
                                    </small>
                                </article>
                            )}
                        </section>
                        <nav>
                            {[
                                ["fueling", "Tankování"],
                                ["expense", "Výdaje"],
                            ].map(([k, n]) => (
                                <button
                                    className={
                                        mode === k ? "active" : "secondary"
                                    }
                                    key={k}
                                    onClick={() => {
                                        setMode(k);
                                        setForm(null);
                                        setPage(1);
                                    }}
                                >
                                    {n}
                                </button>
                            ))}
                            <button
                                disabled={
                                    busy ||
                                    mode === "vehicle" ||
                                    (mode === "fueling" &&
                                        !streams.some(
                                            (s) =>
                                                String(s.vehicle_id) ===
                                                vehicle,
                                        ))
                                }
                                onClick={newEntry}
                            >
                                + Nový záznam
                            </button>
                        </nav>
                    </>
                )}
                {form && (
                    <form className="editor" onSubmit={save}>
                        <h2>
                            {mode === "vehicle"
                                ? "Nové vozidlo"
                                : editId
                                  ? "Upravit záznam"
                                  : mode === "fueling"
                                    ? "Nové tankování"
                                    : "Nový výdaj"}
                        </h2>
                        <div className="fields">
                            {mode === "vehicle" ? (
                                <>
                                    {input(
                                        "Název vozidla",
                                        "name",
                                        "text",
                                        true,
                                    )}
                                    {input("Značka", "make")}
                                    {input("Model", "model")}
                                    {input(
                                        "Registrační značka",
                                        "registration_plate",
                                    )}
                                    {input(
                                        "Počáteční tachometr",
                                        "initial_odometer",
                                        "number",
                                    )}
                                    {select("Vzdálenost", "distance_unit", [
                                        { id: "km", name: "Kilometry" },
                                        { id: "mi", name: "Míle" },
                                    ])}
                                    {select(
                                        "Výchozí palivo",
                                        "fuel_sort_id",
                                        catalogs.fuel_sorts ?? [],
                                    )}
                                </>
                            ) : (
                                <>
                                    {input(
                                        "Datum",
                                        "occurred_on",
                                        "date",
                                        true,
                                    )}
                                    {input(
                                        `Tachometr (${selected?.distance_unit})`,
                                        "odometer",
                                        "number",
                                    )}
                                    {input(
                                        "Celková cena (záporně pro vratku)",
                                        "amount",
                                        "number",
                                        true,
                                    )}
                                    {mode === "fueling" ? (
                                        <>
                                            {select(
                                                "Nádrž / médium",
                                                "energy_stream_id",
                                                streams,
                                            )}
                                            {select(
                                                "Palivo",
                                                "fuel_sort_id",
                                                (
                                                    catalogs.fuel_sorts ?? []
                                                ).filter(
                                                    (f: Row) =>
                                                        f.medium_group ===
                                                        streams.find(
                                                            (s) =>
                                                                String(s.id) ===
                                                                String(
                                                                    form.energy_stream_id,
                                                                ),
                                                        )?.medium_group,
                                                ),
                                            )}
                                            {input(
                                                `Množství (${streams.find((s) => String(s.id) === String(form.energy_stream_id))?.quantity_unit ?? ""})`,
                                                "quantity",
                                                "number",
                                                true,
                                            )}
                                            {select(
                                                "Typ tankování",
                                                "fueling_type_id",
                                                catalogs.fueling_types ?? [],
                                            )}
                                        </>
                                    ) : (
                                        <>
                                            {select(
                                                "Kategorie",
                                                "expense_type_id",
                                                catalogs.expense_types ?? [],
                                                "id",
                                                "label",
                                            )}
                                            {select(
                                                "Opakování (měsíce, bez založení plánu)",
                                                "repetition_months",
                                                catalogs.repetition_intervals ??
                                                    [],
                                            )}
                                        </>
                                    )}
                                </>
                            )}
                            {select(
                                "Měna",
                                "currency_code",
                                catalogs.currencies ?? [],
                                "code",
                                "code",
                            )}
                        </div>
                        {mode !== "vehicle" && (
                            <label>
                                Poznámka
                                <textarea
                                    value={form.note ?? ""}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            note: e.target.value,
                                        })
                                    }
                                />
                            </label>
                        )}
                        <div className="actions">
                            <button disabled={busy}>Uložit</button>
                            <button
                                type="button"
                                className="secondary"
                                onClick={() => setForm(null)}
                            >
                                Zrušit
                            </button>
                        </div>
                    </form>
                )}
                {selected && mode !== "vehicle" && (
                    <section className="history">
                        <h2>
                            {mode === "fueling"
                                ? "Historie tankování"
                                : "Historie výdajů"}
                        </h2>
                        {!events.length ? (
                            <p>
                                Zatím zde nejsou žádné záznamy. Přidejte první.
                            </p>
                        ) : (
                            events.map((e) => (
                                <article className="entry" key={e.id}>
                                    <div>
                                        <strong>
                                            {e.occurred_on
                                                .split("-")
                                                .reverse()
                                                .join(". ")}
                                        </strong>
                                        <small>
                                            {e.odometer
                                                ? `${money(e.odometer)} ${selected.distance_unit}`
                                                : "Tachometr neuveden"}
                                        </small>
                                        <p>{e.note || "Bez poznámky"}</p>
                                    </div>
                                    <div>
                                        <strong>
                                            {money(e.amount)} {e.currency_code}
                                        </strong>
                                        <button
                                            className="secondary"
                                            onClick={() =>
                                                run(async () => {
                                                    const d = await api(
                                                        `/vehicles/${vehicle}/events/${e.id}`,
                                                    );
                                                    setForm({
                                                        ...d.detail,
                                                        ...d.event,
                                                    });
                                                    setEditId(e.id);
                                                })
                                            }
                                        >
                                            Upravit
                                        </button>
                                    </div>
                                </article>
                            ))
                        )}
                        <div className="actions">
                            <button
                                className="secondary"
                                disabled={page === 1}
                                onClick={() => setPage(page - 1)}
                            >
                                Předchozí
                            </button>
                            <span>
                                {page} / {lastPage}
                            </span>
                            <button
                                className="secondary"
                                disabled={page >= lastPage}
                                onClick={() => setPage(page + 1)}
                            >
                                Další
                            </button>
                        </div>
                    </section>
                )}
                <footer>
                    CCM · První verze evidence · Součty jsou oddělené podle měn.
                    Import a výpočty spotřeby budou následovat.
                </footer>
            </main>
        </>
    );
}
createRoot(document.getElementById("root")!).render(<App />);
