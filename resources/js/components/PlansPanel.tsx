import { useEffect, useState, FormEvent } from "react";
type Row = Record<string, any>;
export function PlansPanel({
    vehicle,
    api,
    pay,
}: {
    vehicle: Row;
    api: (path: string, method?: string, data?: Row) => Promise<any>;
    pay: (plan: Row) => void;
}) {
    const [rows, setRows] = useState<Row[]>([]),
        [status, setStatus] = useState("active"),
        [page, setPage] = useState(1),
        [last, setLast] = useState(1),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false),
        [form, setForm] = useState<Row | null>(null);
    const base = `/vehicles/${vehicle.id}/plans`;
    async function load() {
        const d = await api(`${base}?status=${status}&page=${page}`);
        setRows(d.data);
        setLast(d.last_page);
    }
    useEffect(() => {
        setRows([]);
        load().catch((e) => setError(e.message));
    }, [vehicle.id, status, page]);
    async function save(e: FormEvent, end = false) {
        e.preventDefault();
        if (!form) return;
        setBusy(true);
        setError("");
        try {
            await api(`${base}/${form.id}`, "PUT", { ...form, end });
            setForm(null);
            await load();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <section>
            <h2>Opakované výdaje</h2>
            <p>
                Plány pouze připomínají platby. Odhady se nezapočítávají do
                skutečných nákladů. Plán založíte u nového výdaje nebo při
                úpravě výdaje s intervalem opakování.
            </p>
            <label>
                Zobrazit
                <select
                    value={status}
                    onChange={(e) => {
                        setStatus(e.target.value);
                        setPage(1);
                        setForm(null);
                    }}
                >
                    <option value="active">Aktivní plány</option>
                    <option value="ended">Ukončené plány</option>
                    <option value="all">Všechny plány</option>
                </select>
            </label>
            {error && (
                <p role="alert" className="error">
                    {error}
                </p>
            )}
            {form && (
                <form onSubmit={save}>
                    <h3>Upravit plán</h3>
                    <div className="fields">
                        <label>
                            Název
                            <input
                                required
                                maxLength={160}
                                value={form.name}
                                onChange={(e) =>
                                    setForm({ ...form, name: e.target.value })
                                }
                            />
                        </label>
                        <label>
                            Interval (měsíce)
                            <select
                                value={form.interval_months}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        interval_months: e.target.value,
                                    })
                                }
                            >
                                {[1, 3, 6, 12, 24, 36].map((n) => (
                                    <option key={n}>{n}</option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <p>
                        Změna intervalu zachová původní kotevní den a určí první
                        termín po posledním pokrytém období.
                    </p>
                    <button disabled={busy}>Uložit plán</button>{" "}
                    <button
                        disabled={busy}
                        type="button"
                        className="secondary"
                        onClick={(e) => save(e, true)}
                    >
                        Ukončit plán
                    </button>{" "}
                    <button
                        type="button"
                        className="secondary"
                        onClick={() => setForm(null)}
                    >
                        Zrušit
                    </button>
                </form>
            )}
            <div className="history">
                {rows.map((p) => (
                    <article key={p.id}>
                        <h3>{p.name}</h3>
                        <p>
                            {p.ended_on
                                ? `Ukončeno ${p.ended_on}`
                                : `Další termín: ${p.next_due_on}`}{" "}
                            · každých {p.interval_months} měsíců
                        </p>
                        <p>
                            Odhad: {p.estimated_amount} {p.currency_code} ·
                            zdrojový výdaj #{p.source_event_id}
                        </p>
                        {p.end_reason && <p>{p.end_reason}</p>}
                        {!p.ended_on && (
                            <>
                                <button
                                    disabled={!!vehicle.archived_at || busy}
                                    onClick={() => pay(p)}
                                >
                                    Zapsat platbu
                                </button>{" "}
                                <button
                                    className="secondary"
                                    disabled={!!vehicle.archived_at || busy}
                                    onClick={() => setForm({ ...p })}
                                >
                                    Upravit plán
                                </button>
                            </>
                        )}
                    </article>
                ))}
            </div>
            {!rows.length && <p>Žádné plány v tomto výběru.</p>}
            <div className="pagination">
                <button
                    disabled={page <= 1 || busy}
                    onClick={() => setPage(page - 1)}
                >
                    Předchozí
                </button>
                <span>
                    {page} / {last}
                </span>
                <button
                    disabled={page >= last || busy}
                    onClick={() => setPage(page + 1)}
                >
                    Další
                </button>
            </div>
        </section>
    );
}
