// projects.jsx — project list (5.4) and a phase-1 project detail (5.6: money levels, budget lines, ledger, history)

const VIEW_KEY = 'vecplan_project_views';
const BUILTIN_VIEWS = [
  { id: 'all', label: 'ทั้งหมด', f: {} },
  { id: 'approved', label: 'อนุมัติในแผน', f: { status: 'approved' } },
  { id: 'waitlisted', label: 'รอเงินเพิ่ม', f: { status: 'waitlisted' } },
];
function loadViews() { try { return JSON.parse(localStorage.getItem(VIEW_KEY) || '[]'); } catch (e) { return []; } }
function storeViews(v) { try { localStorage.setItem(VIEW_KEY, JSON.stringify(v)); } catch (e) { /* storage blocked */ } }

function ProjectsPage() {
  const { meta, funds, units } = useApp();
  const { params } = parseHash();
  const [f, setF] = useState({ q: params.q || '', status: params.status || '', fund: params.fund || '', quarter: '', unit: params.unit || '' });
  const [qLive, setQLive] = useState(f.q);
  const [views, setViews] = useState(loadViews());
  const [adding, setAdding] = useState(false);
  useEffect(() => { const t = setTimeout(() => setF(x => ({ ...x, q: qLive })), 300); return () => clearTimeout(t); }, [qLive]);
  const { data, error, loading, reload } = useApi('projects/index', f);
  if (!meta.permissions.view_projects) return <Forbidden />;
  const rows = data ? data.rows : [];
  const sum = k => rows.reduce((a, r) => a + r[k], 0);
  const allViews = [...BUILTIN_VIEWS, ...views];
  const sameView = v => ['q', 'status', 'fund', 'quarter', 'unit'].every(k => (v.f[k] || '') === (f[k] || ''));
  const saveView = () => {
    const label = prompt('ชื่อมุมมอง');
    if (!label) return;
    const nv = [...views, { id: 'u' + Date.now(), label, f: { ...f } }];
    setViews(nv); storeViews(nv);
  };
  const removeView = id => { const nv = views.filter(v => v.id !== id); setViews(nv); storeViews(nv); };
  const fundCards = funds.roots.flatMap(r => r.fund_type ? [r] : r.children);

  return (
    <div className="stack">
      <PageHead crumb="โครงการ" title="โครงการทั้งหมด">
        <button className="btn" onClick={() => download('projects/export', f)}><Icon name="download" size={16} />ส่งออก Excel</button>
        {meta.permissions.create_project && <button className="btn" onClick={() => setAdding(true)}><Icon name="filePlus" size={16} />เพิ่มโครงการที่อนุมัติแล้ว</button>}
        {meta.permissions.import && <button className="btn primary" onClick={() => navigate('import')}>นำเข้าแผนที่อนุมัติ</button>}
      </PageHead>
      {adding && <ApprovedProjectEditor onClose={() => setAdding(false)} onSaved={r => { setAdding(false); navigate('project', { id: r.id }); }} />}
      <div className="card has-mobile-cards">
        <div className="row wrap" style={{ gap: 6, padding: '12px 16px', borderBottom: '1px solid var(--line-soft)' }}>
          <span className="sm muted" style={{ marginRight: 4 }}>มุมมองที่บันทึกไว้</span>
          {allViews.map(v => (
            <span key={v.id} className={'chip' + (sameView(v) ? ' on' : '')} onClick={() => { setF({ q: '', status: '', fund: '', quarter: '', unit: '', ...v.f }); setQLive(v.f.q || ''); }}>
              {v.label}{v.id.startsWith('u') && <span title="ลบมุมมอง" onClick={e => { e.stopPropagation(); removeView(v.id); }} style={{ marginLeft: 4, opacity: .6 }}>✕</span>}
            </span>
          ))}
          <button className="chip" style={{ borderStyle: 'dashed', color: 'var(--link)' }} onClick={saveView}>+ บันทึกมุมมองนี้</button>
        </div>
        <div className="row wrap" style={{ gap: 10, padding: '12px 16px' }}>
          <div className="search"><Icon name="search" size={16} /><input value={qLive} onChange={e => setQLive(e.target.value)} placeholder="ค้นหารหัส ชื่อโครงการ หรือหน่วยงาน" aria-label="ค้นหา" /></div>
          <select className="select" value={f.status} onChange={e => setF({ ...f, status: e.target.value })} aria-label="สถานะ">
            <option value="">ทุกสถานะ</option>{Object.entries(PROJECT_STATUS).map(([k, v]) => <option key={k} value={k}>{v[0]}</option>)}
          </select>
          <select className="select" value={f.fund} onChange={e => setF({ ...f, fund: e.target.value })} aria-label="แหล่งเงิน">
            <option value="">ทุกแหล่งเงิน</option>{fundCards.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
          <select className="select" value={f.quarter} onChange={e => setF({ ...f, quarter: e.target.value })} aria-label="ไตรมาส">
            <option value="">ทุกไตรมาส</option>{Object.entries(QUARTERS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
          <select className="select" value={f.unit} onChange={e => setF({ ...f, unit: e.target.value })} aria-label="หน่วยงาน" style={{ maxWidth: 260 }}>
            <option value="">ทุกฝ่าย/งาน/แผนกวิชา</option>
            {units.flat.filter(u => u.kind !== 'section' || u.depth < 2).map(u => <option key={u.id} value={u.id}>{'  '.repeat(u.depth)}{u.name}</option>)}
          </select>
          <div style={{ flex: 1 }} />
          <span className="sm muted">ผลรวมที่แสดง: ขอ <b style={{ color: 'var(--text)' }}>{fmt(sum('requested'))}</b> · อนุมัติ <b style={{ color: 'var(--text)' }}>{fmt(sum('allocated'))}</b> · จ่ายจริง <b style={{ color: 'var(--text)' }}>{fmt(sum('spent'))}</b></span>
        </div>
        {error && <div style={{ padding: '0 16px 16px' }}><LoadError error={error} onRetry={reload} /></div>}
        <div className="table-wrap" style={{ borderTop: '1px solid var(--line)', opacity: loading ? .6 : 1 }}>
          <table className="tbl" style={{ minWidth: 1080 }}>
            <thead><tr>
              <th>รหัส</th><th>ชื่อโครงการ</th><th>หน่วยงาน</th><th>แหล่งเงิน</th><th className="th-num">ขอ</th><th className="th-num">อนุมัติ</th>
              <th className="th-num">จ่ายจริง</th><th>ไตรมาสที่วางแผน</th><th>สถานะ</th><th>สัญญาณไฟ</th>
            </tr></thead>
            <tbody>
              {rows.map(p => (
                <tr key={p.id} className="click" onClick={() => navigate('project', { id: p.id })}>
                  <td className="mono nowrap" style={{ fontSize: 12.5, color: 'var(--text-2)' }}>{p.code}</td>
                  <td style={{ fontWeight: 500, color: 'var(--link)', lineHeight: 1.4 }}>{p.title}</td>
                  <td className="sm" style={{ color: 'var(--text-3)' }}>{p.unit_name}</td>
                  <td className="sm nowrap">{p.fund_ids.map(id => <div key={id}><FundTag color={funds.colorOf(id)}>{funds.byId[id] ? funds.byId[id].name : id}</FundTag></div>)}</td>
                  <td className="num">{fmt(p.requested)}</td>
                  <td className="num" style={{ fontWeight: 500 }}>{p.allocated ? fmt(p.allocated) : '—'}</td>
                  <td className="num">{p.spent ? fmt(p.spent) : '—'}</td>
                  <td className="sm nowrap">{quarterText(p.quarters)}</td>
                  <td><StatusBadge status={p.status} /></td>
                  <td><HealthDot health={p.health} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="mobile-cards">
          {rows.map(p => (
            <a key={p.id} className="mcard" onClick={() => navigate('project', { id: p.id })} style={{ color: 'var(--text)', textDecoration: 'none' }}>
              <div className="row" style={{ justifyContent: 'space-between' }}><span className="xs mono muted">{p.code}</span><StatusBadge status={p.status} /></div>
              <div style={{ fontWeight: 500 }}>{p.title}</div>
              <div className="xs muted">{p.unit_name} · {quarterText(p.quarters)}</div>
              <div className="xs">ขอ {fmt(p.requested)} · อนุมัติ <b>{fmt(p.allocated)}</b> · จ่าย {fmt(p.spent)}</div>
            </a>
          ))}
        </div>
        {data && rows.length === 0 && (
          <div style={{ padding: '40px 16px', textAlign: 'center' }} className="muted">
            ไม่พบโครงการตามเงื่อนไข · <a onClick={() => { setF({ q: '', status: '', fund: '', quarter: '', unit: '' }); setQLive(''); }}>ล้างตัวกรอง</a>
          </div>
        )}
        <div className="pager"><span>แสดง {rows.length} โครงการ</span></div>
      </div>
    </div>
  );
}

function ProjectPage() {
  const { meta, funds } = useApp();
  const { params } = parseHash();
  const { data, error, loading, reload } = useApi('projects/show', { id: params.id });
  const [tab, setTab] = useState('overview');
  const ledger = useApi('ledger/index', { project_id: params.id, per: 200 }, [tab === 'ledger']);
  if (loading && !data) return <Skeleton />;
  if (error) return <LoadError error={error} onRetry={reload} />;
  const p = data.project;
  const totals = data.balances.reduce((a, b) => ({ allocated: a.allocated + b.allocated, spent: a.spent + b.spent, committed: a.committed + b.committed_open }), { allocated: 0, spent: 0, committed: 0 });
  const tabs = [['overview', 'ภาพรวม'], ['budget', 'งบประมาณ'], data.can_view_ledger && ['ledger', 'ที่มาของเงิน / สมุดบัญชี'], ['history', 'ประวัติ']].filter(Boolean);
  return (
    <div className="stack">
      <div className="crumb row" style={{ gap: 6 }}><a onClick={() => navigate('projects')}>โครงการทั้งหมด</a><span>/</span><span>{p.code}</span></div>
      <div className="card" style={{ padding: '18px 20px', display: 'flex', flexDirection: 'column', gap: 16 }}>
        <div className="row wrap" style={{ gap: 8 }}>
          <span className="mono sm" style={{ padding: '2px 8px', borderRadius: 4, background: 'var(--track)', color: 'var(--text-2)' }}>{p.code}</span>
          <StatusBadge status={p.status} /><HealthDot health={p.health} />
          {data.balances.map(b => <FundTag key={b.fund_source_id} color={funds.colorOf(b.fund_source_id)}><span className="sm">{funds.label(b.fund_source_id)}</span></FundTag>)}
        </div>
        <h1 className="title" style={{ margin: 0 }}>{p.title}</h1>
        <div className="sm muted row wrap" style={{ gap: '6px 16px' }}>
          <span>{p.unit_name}</span><span>{quarterText(p.quarters)}</span>
          <span>ที่มา: {p.source === 'import' ? 'นำเข้าแผนที่อนุมัติ' : p.source === 'demo' ? 'ข้อมูลตัวอย่าง' : p.source === 'manual' ? 'เพิ่มโดยผู้ดูแล (อนุมัติแล้ว)' : 'เสนอผ่านระบบ'}</span>
          {p.approved_at && <span>อนุมัติเมื่อ {thDate(p.approved_at)}</span>}
        </div>
        <div style={{ borderTop: '1px solid var(--line-soft)', paddingTop: 16 }} className="stack">
          <div className="row wrap" style={{ justifyContent: 'space-between' }}>
            <span style={{ fontWeight: 600 }}>เส้นทางเงิน 4 ระดับ</span>
            <span className="xs muted">การขออนุญาต ผูกพัน และเบิกจ่ายเปิดใช้ในระยะที่ 2</span>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 10 }}>
            {[['อนุมัติ', totals.allocated, 'ยอดจัดสรรสุทธิ (BR-25)'], ['อนุญาตแล้ว', 0, 'ระยะที่ 2'], ['ผูกพัน', totals.committed, 'ระยะที่ 2'], ['จ่ายจริง', totals.spent, pct(totals.spent, totals.allocated).toFixed(1) + '% ของอนุมัติ']].map(([l, v, n], i) => (
              <div key={l} style={{ display: 'flex', flexDirection: 'column', gap: 4, padding: '10px 12px', border: '1px solid var(--line)', borderRadius: 8 }}>
                <span className="xs muted">{i + 1}. {l}</span><span style={{ fontSize: 17, fontWeight: 600 }}>{fmt(v)}</span><span className="xs muted">{n}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
      <div className="row" style={{ gap: 2, borderBottom: '1px solid var(--chip-line)', overflowX: 'auto' }} role="tablist">
        {tabs.map(([k, l]) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
            style={{ height: 42, padding: '0 16px', border: 0, background: 'transparent', fontSize: 13.5, cursor: 'pointer', whiteSpace: 'nowrap', marginBottom: -1,
              borderBottom: '2px solid ' + (tab === k ? 'var(--navy)' : 'transparent'), color: tab === k ? 'var(--heading)' : 'var(--muted)', fontWeight: tab === k ? 600 : 400 }}>{l}</button>
        ))}
      </div>
      {tab === 'overview' && (
        <div className="grid-auto">
          <div className="card card-b">
            <h3 className="h" style={{ fontSize: 14, marginBottom: 8 }}>ยอดต่อแหล่งเงิน</h3>
            <table className="tbl dense"><thead><tr><th>แหล่งเงิน</th><th className="th-num">อนุมัติ</th><th className="th-num">จ่ายจริง</th><th className="th-num">คงเหลือ (free)</th></tr></thead>
              <tbody>{data.balances.map(b => (
                <tr key={b.fund_source_id}><td className="sm"><FundTag color={funds.colorOf(b.fund_source_id)}>{funds.label(b.fund_source_id)}</FundTag></td>
                  <td className="num">{fmt(b.allocated)}</td><td className="num">{fmt(b.spent)}</td><td className="num" style={{ fontWeight: 600 }}>{fmt(b.free)}</td></tr>
              ))}{data.balances.length === 0 && <tr><td colSpan={4} className="muted sm">ยังไม่มีการจัดสรรเงิน</td></tr>}</tbody>
            </table>
          </div>
          <div className="card card-b">
            <h3 className="h" style={{ fontSize: 14, marginBottom: 8 }}>ข้อมูลโครงการ</h3>
            {[['รหัส', p.code], ['หน่วยงาน', p.unit_name], ['ยอดขอ', fmt(p.requested)], ['ไตรมาสที่วางแผน', quarterText(p.quarters)], ['ผู้บันทึก', p.created_by_name || '—'], ['สร้างเมื่อ', thDate(p.created_at, true)]].map(([k, v]) => (
              <div key={k} style={{ display: 'grid', gridTemplateColumns: '150px minmax(0,1fr)', gap: 12, padding: '9px 0', borderBottom: '1px solid var(--row-line)', fontSize: 13 }}><span className="muted">{k}</span><span>{v}</span></div>
            ))}
          </div>
        </div>
      )}
      {tab === 'budget' && (
        <div className="card">
          <div className="card-h"><h3>รายการค่าใช้จ่าย</h3></div>
          <div className="table-wrap"><table className="tbl" style={{ minWidth: 560 }}>
            <thead><tr><th>หมวด · รายการ</th><th>แหล่งเงิน</th><th className="th-num">จำนวน</th><th className="th-num">รวม (บาท)</th></tr></thead>
            <tbody>{data.lines.map(l => (
              <tr key={l.id}><td><div className="xs muted">{l.category_name}</div>{l.item_name}</td><td className="sm"><FundTag color={funds.colorOf(+l.fund_source_id)}>{l.fund_name}</FundTag></td>
                <td className="num sm">{(+l.quantity).toLocaleString()} {l.unit || ''}</td><td className="num" style={{ fontWeight: 500 }}>{fmt(l.amount_num)}</td></tr>
            ))}</tbody>
            <tfoot><tr><td colSpan={3}>รวมทั้งสิ้น</td><td className="num">{fmt(data.lines.reduce((a, l) => a + l.amount_num, 0))}</td></tr></tfoot>
          </table></div>
        </div>
      )}
      {tab === 'ledger' && (
        <div className="card">
          <div className="card-h"><h3>รายการเงินของโครงการ</h3><a className="sm" onClick={() => navigate('ledger', { project_id: p.id })}>เปิดในสมุดบัญชี</a></div>
          <div className="table-wrap"><table className="tbl" style={{ minWidth: 760 }}>
            <thead><tr><th>เลขที่</th><th>วันที่</th><th>ประเภท</th><th>จาก → ไป</th><th className="th-num">จำนวน</th><th>อ้างอิง</th></tr></thead>
            <tbody>{(ledger.data ? ledger.data.rows : []).map(r => (
              <tr key={r.id} className={r.reversed_by_id ? 'struck' : ''}>
                <td className="mono xs">{r.entry_no}</td><td className="sm nowrap">{thDate(r.entry_date)}</td><td><TypeBadge type={r.entry_type} label={r.type_label} /></td>
                <td className={'sm' + (r.reversed_by_id ? ' struck-text' : '')}>{r.from} → {r.to}{r.override_reason && <div className="xs" style={{ color: 'var(--orange-fg)' }}>ยืนยันข้าม BR-22: {r.override_reason}</div>}</td>
                <td className="num">{fmt(r.amount)}</td><td className="sm">{r.reference_no || '—'}</td>
              </tr>
            ))}</tbody>
          </table></div>
        </div>
      )}
      {tab === 'history' && (
        <div className="card">
          <div className="card-h"><span className="sm muted">บันทึกการเปลี่ยนแปลงทั้งหมด · แก้ไขหรือลบไม่ได้</span></div>
          <div className="table-wrap"><table className="tbl">
            <thead><tr><th>เวลา</th><th>ผู้ดำเนินการ</th><th>การกระทำ</th><th>รายละเอียด</th></tr></thead>
            <tbody>{data.history.map((h, i) => (
              <tr key={i}><td className="sm nowrap">{thDate(h.created_at, true)}</td><td className="sm">{h.user_name || 'ระบบ'}</td><td className="sm" style={{ fontWeight: 500 }}>{h.action}</td>
                <td className="xs mono muted" style={{ maxWidth: 420, overflow: 'hidden', textOverflow: 'ellipsis' }}>{h.after}</td></tr>
            ))}{data.history.length === 0 && <tr><td colSpan={4} className="sm muted">ไม่มีประวัติ</td></tr>}</tbody>
          </table></div>
        </div>
      )}
    </div>
  );
}

// Records a project that was approved outside the system: it lands as "approved" with its money allocated from the fund pools.
function ApprovedProjectEditor({ onClose, onSaved }) {
  const { meta, units, toast } = useApp();
  const cats = meta.categories.filter(c => +c.is_leaf && +c.active);
  const blank = () => ({ category_id: '', fund_id: '', item_name: '', amount: '' });
  const [f, setF] = useState({ title: '', code: '', unit_id: '', requested: '', quarters: [], lines: [blank()] });
  const [override, setOverride] = useState('');
  const [err, setErr] = useState(null);
  const [busy, run] = useBusy();
  const setLine = (i, patch) => setF({ ...f, lines: f.lines.map((l, j) => j === i ? { ...l, ...patch } : l) });
  const total = f.lines.reduce((a, l) => a + (parseMoney(l.amount) || 0), 0);
  const requested = f.requested === '' ? total : (parseMoney(f.requested) || 0);
  const save = () => run(async () => {
    setErr(null);
    try {
      const r = await post('projects/create', {
        title: f.title, code: f.code, unit_id: +f.unit_id, quarters: f.quarters,
        requested: f.requested === '' ? '' : (parseMoney(f.requested) || 0),
        lines: f.lines.map(l => ({ category_id: +l.category_id, fund_id: +l.fund_id, item_name: l.item_name, amount: parseMoney(l.amount) || 0 })),
        override_reason: override || undefined,
      });
      toast('เพิ่มโครงการ ' + r.code + ' แล้ว', 'ok');
      onSaved(r);
    } catch (e) { setErr(e); }
  });
  return (
    <Modal wide title="เพิ่มโครงการที่ได้รับอนุมัติแล้ว" onClose={onClose} footer={<Fragment>
      <span className="sm muted" style={{ marginRight: 'auto' }}>ขอ <b style={{ color: 'var(--text)' }}>{fmt(requested)}</b> · อนุมัติ <b style={{ color: 'var(--text)' }}>{fmt(total)}</b> บาท</span>
      <button className="btn" onClick={onClose}>ยกเลิก</button>
      <button className="btn primary" onClick={save} disabled={busy}>บันทึกและจัดสรรเงิน</button>
    </Fragment>}>
      <Alert tone="blue">โครงการจะมีสถานะ "อนุมัติในแผน" ทันที และระบบจะจัดสรรเงินจากกองเงินตามแหล่งเงินของแต่ละรายการ</Alert>
      <Field label="ชื่อโครงการ" required><input className="input" value={f.title} onChange={e => setF({ ...f, title: e.target.value })} /></Field>
      <div className="form-grid">
        <Field label="หน่วยงาน" required>
          <UnitPicker value={f.unit_id} onChange={v => setF({ ...f, unit_id: v })} placeholder="พิมพ์เพื่อค้นหาหน่วยงาน" />
        </Field>
        <Field label="รหัสโครงการ" hint="เว้นว่างให้ระบบออกเลขให้"><input className="input mono" value={f.code} onChange={e => setF({ ...f, code: e.target.value })} /></Field>
      </div>
      <div className="form-grid">
        <Field label="ยอดเงินที่ขออนุมัติ (บาท)" hint="เว้นว่าง = เท่ากับยอดที่อนุมัติ"><MoneyInput value={f.requested} onChange={v => setF({ ...f, requested: v })} placeholder={fmt(total)} /></Field>
        <Field label="ยอดเงินที่อนุมัติ (บาท)" hint="รวมจากรายการงบประมาณด้านล่าง"><input className="input num" readOnly value={fmt(total)} style={{ fontWeight: 600, background: 'var(--surface-2)' }} /></Field>
      </div>
      <Field label="ไตรมาสที่วางแผน">
        <div className="row wrap" style={{ gap: 12 }}>{[1, 2, 3, 4].map(q => (
          <label key={q} className="check"><input type="checkbox" checked={f.quarters.includes(q)}
            onChange={e => setF({ ...f, quarters: e.target.checked ? [...f.quarters, q] : f.quarters.filter(x => x !== q) })} /> ไตรมาส {q}</label>
        ))}</div>
      </Field>
      <div style={{ fontWeight: 600, marginTop: 4 }}>รายการงบประมาณที่อนุมัติ</div>
      {f.lines.map((l, i) => (
        <div key={i} className="row wrap" style={{ gap: 8, alignItems: 'flex-end', paddingBottom: 8, borderBottom: '1px solid var(--line-soft)' }}>
          <Field label="หมวดรายจ่าย" required style={{ flex: '1 1 160px' }}>
            <select className="select" value={l.category_id} onChange={e => setLine(i, { category_id: e.target.value })}>
              <option value="">เลือกหมวด</option>{cats.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </Field>
          <Field label="แหล่งเงิน" required style={{ flex: '1 1 160px' }}><FundSelect value={l.fund_id} onChange={v => setLine(i, { fund_id: v })} /></Field>
          <Field label="รายการ" style={{ flex: '2 1 180px' }}><input className="input" value={l.item_name} onChange={e => setLine(i, { item_name: e.target.value })} placeholder="ไม่ระบุ = ใช้ชื่อหมวด" /></Field>
          <Field label="ยอดอนุมัติ (บาท)" required style={{ flex: '1 1 120px' }}><MoneyInput value={l.amount} onChange={v => setLine(i, { amount: v })} /></Field>
          {f.lines.length > 1 && <button className="icon-btn plain" title="ลบรายการ" onClick={() => setF({ ...f, lines: f.lines.filter((_, j) => j !== i) })} style={{ marginBottom: 4 }}><Icon name="x" size={16} /></button>}
        </div>
      ))}
      <button className="btn sm" style={{ alignSelf: 'flex-start' }} onClick={() => setF({ ...f, lines: [...f.lines, blank()] })}>+ เพิ่มรายการ</button>
      {err && <Alert tone="red">{err.message}</Alert>}
      <Field label="หมายเหตุกรณีกองเงินไม่พอ" hint="บันทึกได้แม้กองเงินติดลบ (ข้าม BR-22) — ระบบเก็บเหตุผลนี้ไว้ในสมุดบัญชี"><input className="input" value={override} onChange={e => setOverride(e.target.value)} placeholder="เช่น รอรับเงินงวดถัดไป" /></Field>
    </Modal>
  );
}

Object.assign(window, { ProjectsPage, ProjectPage });
