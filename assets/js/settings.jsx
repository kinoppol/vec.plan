// settings.jsx — master data (spec §4.1, design 5.18): fiscal years, org units, fund sources, categories, alignment, approval chains

const SETTINGS_TABS = [['general', 'ทั่วไป / ปีงบประมาณ'], ['units', 'หน่วยงาน'], ['funds', 'แหล่งเงิน'], ['categories', 'หมวดรายจ่าย'], ['alignment', 'ความสอดคล้อง'], ['chains', 'สายอนุมัติ']];
const FY_STATUS = { setup: 'ตั้งค่า', proposal_open: 'เปิดรับข้อเสนอ', deliberation: 'พิจารณางบประมาณ', execution: 'ดำเนินการ', closing: 'กำลังปิดปี', closed: 'ปิดปีแล้ว' };
const UNIT_KIND = { division: 'ฝ่าย', section: 'งาน', department: 'แผนกวิชา' };
const SCOPE_LABEL = { project_unit: 'หน่วยงานของโครงการ', project_division: 'ฝ่ายของโครงการ', global: 'ทั้งวิทยาลัย' };
const WEIGHT_LABEL = { necessity: 'ความจำเป็น', impact: 'ผลกระทบ', alignment: 'ความสอดคล้อง', beneficiaries: 'ผู้ได้รับประโยชน์', past: 'ผลปีก่อน' };

function SettingsPage() {
  const { meta, toast, reloadMeta } = useApp();
  const { params } = parseHash();
  const [tab, setTab] = useState(params.tab || 'general');
  const { data, error, loading, reload } = useApi('settings/index');
  const [edit, setEdit] = useState(null);
  if (!meta.permissions.settings && !meta.permissions.admin) return <Forbidden />;
  const readOnly = !meta.permissions.settings;
  const save = async (path, body, msg) => {
    await post(path, body);
    toast(msg || 'บันทึกแล้ว', 'ok');
    setEdit(null);
    reload();
    reloadMeta();
  };
  return (
    <div className="stack">
      <PageHead crumb="ตั้งค่า" title="ข้อมูลหลักของระบบ" />
      {readOnly && <Alert tone="blue">ผู้ดูแลระบบแก้ไขได้เฉพาะชื่อสถานศึกษาและปีงบประมาณปัจจุบัน — ข้อมูลหลักอื่นแก้ไขโดยงานวางแผนและงบประมาณ</Alert>}
      <div className="row" style={{ gap: 2, borderBottom: '1px solid var(--chip-line)', overflowX: 'auto' }} role="tablist">
        {SETTINGS_TABS.map(([k, l]) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
            style={{ height: 42, padding: '0 16px', border: 0, background: 'transparent', fontSize: 13.5, cursor: 'pointer', whiteSpace: 'nowrap', marginBottom: -1,
              borderBottom: '2px solid ' + (tab === k ? 'var(--navy)' : 'transparent'), color: tab === k ? 'var(--heading)' : 'var(--muted)', fontWeight: tab === k ? 600 : 400 }}>{l}</button>
        ))}
      </div>
      {loading && !data && <Skeleton rows={6} />}
      {error && <LoadError error={error} onRetry={reload} />}
      {data && tab === 'general' && <GeneralSettings data={data} readOnly={readOnly} onEdit={setEdit} save={save} />}
      {data && tab === 'units' && <UnitSettings data={data} readOnly={readOnly} onEdit={setEdit} />}
      {data && tab === 'funds' && <FundSettings data={data} readOnly={readOnly} onEdit={setEdit} />}
      {data && tab === 'categories' && <CategorySettings data={data} readOnly={readOnly} onEdit={setEdit} />}
      {data && tab === 'alignment' && <AlignmentSettings data={data} readOnly={readOnly} onEdit={setEdit} />}
      {data && tab === 'chains' && <ChainSettings data={data} readOnly={readOnly} save={save} />}
      {edit && <SettingsEditor edit={edit} data={data} onClose={() => setEdit(null)} save={save} />}
    </div>
  );
}

function GeneralSettings({ data, readOnly, onEdit, save }) {
  const { meta, toast } = useApp();
  const [org, setOrg] = useState(data.general.org_name);
  const [cur, setCur] = useState(data.general.current_fiscal_year_id);
  return (
    <div className="stack">
      <div className="card card-b stack" style={{ gap: 14 }}>
        <div className="form-grid">
          <Field label="ชื่อสถานศึกษา"><input className="input" value={org} onChange={e => setOrg(e.target.value)} /></Field>
          <Field label="ปีงบประมาณปัจจุบัน (ค่าเริ่มต้นเมื่อเข้าระบบ)">
            <select className="select" value={cur} onChange={e => setCur(+e.target.value)}>{data.fiscal_years.map(f => <option key={f.id} value={f.id}>{f.year_be}</option>)}</select>
          </Field>
        </div>
        <div className="row" style={{ justifyContent: 'flex-end' }}>
          <button className="btn primary" onClick={() => save('settings/general', { org_name: org, current_fiscal_year_id: cur }).catch(e => toast(e.message, 'err'))}>บันทึก</button>
        </div>
      </div>
      <div className="card">
        <div className="card-h"><h3>ปีงบประมาณ</h3>{!readOnly && <button className="btn sm primary" onClick={() => onEdit({ kind: 'fy', row: { status: 'setup', copy_from: meta.fy, settings: { weights: { ...meta.fiscal_year.settings.weights }, health: { report_due_days: 15 }, adjustment_compare_mode: 'per_fund' } } })}>+ เพิ่มปีงบประมาณ</button>}</div>
        <div className="table-wrap"><table className="tbl">
          <thead><tr><th>ปี</th><th>ช่วงเวลา</th><th>สถานะ</th><th>รับข้อเสนอ</th><th>แผนตั้งต้น</th><th>น้ำหนักคะแนน</th>{!readOnly && <th />}</tr></thead>
          <tbody>{data.fiscal_years.map(f => (
            <tr key={f.id}>
              <td style={{ fontWeight: 600 }}>{f.year_be}{+f.id === +data.general.current_fiscal_year_id && <span className="xs muted"> (ปัจจุบัน)</span>}</td>
              <td className="sm nowrap">{thDate(f.starts_on)} – {thDate(f.ends_on)}</td>
              <td><Badge bg="var(--gray-bg)" fg="var(--gray-fg)">{FY_STATUS[f.status]}</Badge></td>
              <td className="sm">{f.proposal_open_from ? thDate(f.proposal_open_from) + ' – ' + thDate(f.proposal_open_to) : '—'}</td>
              <td className="sm">{f.baseline_locked_at ? 'ล็อก ' + thDate(f.baseline_locked_at) : 'ยังไม่ล็อก'}</td>
              <td className="xs muted">{f.settings.weights ? Object.entries(f.settings.weights).map(([k, v]) => WEIGHT_LABEL[k] + ' ' + v).join(' · ') : '—'}</td>
              {!readOnly && <td><button className="btn sm" onClick={() => onEdit({ kind: 'fy', row: f })}>แก้ไข</button></td>}
            </tr>
          ))}</tbody>
        </table></div>
      </div>
    </div>
  );
}

function UnitSettings({ data, readOnly, onEdit }) {
  const units = buildUnits(data.units);
  return (
    <div className="card">
      <div className="card-h"><h3>หน่วยงาน (ฝ่าย › งาน / แผนกวิชา)</h3>{!readOnly && <button className="btn sm primary" onClick={() => onEdit({ kind: 'unit', row: { kind: 'section', active: 1, sort: 0 } })}>+ เพิ่มหน่วยงาน</button>}</div>
      <div className="table-wrap"><table className="tbl dense">
        <thead><tr><th>ชื่อ</th><th>ประเภท</th><th>รหัส</th><th className="th-num">ผู้เรียน ปวช./ปวส.</th><th className="th-num">โครงการ</th><th>สถานะ</th>{!readOnly && <th />}</tr></thead>
        <tbody>{units.flat.map(u => (
          <tr key={u.id} style={{ opacity: +u.active ? 1 : .5 }}>
            <td style={{ paddingLeft: 16 + u.depth * 22, fontWeight: u.depth === 0 ? 600 : 400 }}>{u.name}</td>
            <td className="sm">{UNIT_KIND[u.kind]}</td><td className="mono xs">{u.code || '—'}</td>
            <td className="num sm">{u.student_count_vc || u.student_count_hvc ? (u.student_count_vc || 0) + ' / ' + (u.student_count_hvc || 0) : '—'}</td>
            <td className="num sm">{u.project_count}</td>
            <td className="sm">{+u.active ? 'ใช้งาน' : 'ปิด'}</td>
            {!readOnly && <td><button className="btn sm" onClick={() => onEdit({ kind: 'unit', row: u })}>แก้ไข</button></td>}
          </tr>
        ))}</tbody>
      </table></div>
    </div>
  );
}

function FundSettings({ data, readOnly, onEdit }) {
  const tree = buildFunds(data.funds.map(f => ({ ...f, color: f.color_token })));
  const flat = [];
  const walk = (f, d) => { flat.push({ ...f, depth: d }); f.children.sort((a, b) => a.sort - b.sort || a.id - b.id).forEach(c => walk(c, d + 1)); };
  tree.roots.sort((a, b) => a.sort - b.sort || a.id - b.id).forEach(r => walk(r, 0));
  const catName = Object.fromEntries(data.categories.map(c => [+c.id, c.name]));
  return (
    <div className="card">
      <div className="card-h"><h3>แหล่งเงิน (ปีงบประมาณนี้)</h3>{!readOnly && <button className="btn sm primary" onClick={() => onEdit({ kind: 'fund', row: { active: 1, sort: 0 } })}>+ เพิ่มแหล่งเงิน</button>}</div>
      <div className="xs muted" style={{ padding: '10px 18px 0' }}>โครงการและรายการเงินอ้างถึงได้เฉพาะแหล่งเงินระดับย่อยสุด (leaf) · หมวดที่อนุญาตว่าง = ใช้ได้ทุกหมวด (BR-06)</div>
      <div className="table-wrap"><table className="tbl dense">
        <thead><tr><th>แหล่งเงิน</th><th>รหัส</th><th>ประเภท</th><th>กฎตรวจเงินก่อนอนุญาต</th><th>หมวดที่อนุญาต</th><th>สถานะ</th>{!readOnly && <th />}</tr></thead>
        <tbody>{flat.map(f => (
          <tr key={f.id} style={{ opacity: f.active ? 1 : .5 }}>
            <td style={{ paddingLeft: 16 + f.depth * 22 }}><span className="row"><span className="sw" style={{ background: tree.colorOf(f.id) }} /><span style={{ fontWeight: f.depth === 0 ? 600 : 400 }}>{f.name}</span>{f.is_leaf && <span className="xs muted">leaf</span>}</span></td>
            <td className="mono xs">{f.code}</td>
            <td className="sm">{FUND_TYPE_LABEL[f.fund_type] || '—'}</td>
            <td className="sm">{PERMIT_RULE_LABEL[f.permit_rule] || '—'}</td>
            <td className="xs">{f.is_leaf ? (f.allowed_category_ids.length ? f.allowed_category_ids.map(id => catName[id]).join(', ') : <span className="muted">ทุกหมวด</span>) : ''}</td>
            <td className="sm">{f.active ? 'ใช้งาน' : 'ปิด'}</td>
            {!readOnly && <td className="nowrap">
              <button className="btn sm" onClick={() => onEdit({ kind: 'fund', row: f })}>แก้ไข</button>
              {f.is_leaf && <button className="btn sm" style={{ marginLeft: 4 }} onClick={() => onEdit({ kind: 'fund_cats', row: f })}>หมวด</button>}
            </td>}
          </tr>
        ))}</tbody>
      </table></div>
    </div>
  );
}

function CategorySettings({ data, readOnly, onEdit }) {
  const top = data.categories.filter(c => !c.parent_id);
  return (
    <div className="card">
      <div className="card-h"><h3>หมวดรายจ่าย (หมวด › หมวดย่อย)</h3>{!readOnly && <button className="btn sm primary" onClick={() => onEdit({ kind: 'category', row: { active: 1, sort: 0 } })}>+ เพิ่มหมวด</button>}</div>
      <div className="table-wrap"><table className="tbl dense">
        <thead><tr><th>หมวด</th><th>รหัส</th><th className="th-num">ใช้ในรายการงบ</th><th>สถานะ</th>{!readOnly && <th />}</tr></thead>
        <tbody>{top.map(t => [t, ...data.categories.filter(c => +c.parent_id === +t.id)].map((c, i) => (
          <tr key={c.id} style={{ opacity: +c.active ? 1 : .5 }}>
            <td style={{ paddingLeft: i ? 38 : 16, fontWeight: i ? 400 : 600 }}>{c.name}</td><td className="mono xs">{c.code}</td>
            <td className="num sm">{c.line_count}</td><td className="sm">{+c.active ? 'ใช้งาน' : 'ปิด'}</td>
            {!readOnly && <td><button className="btn sm" onClick={() => onEdit({ kind: 'category', row: c })}>แก้ไข</button></td>}
          </tr>
        )))}</tbody>
      </table></div>
    </div>
  );
}

function AlignmentSettings({ data, readOnly, onEdit }) {
  return (
    <div className="stack">
      {!readOnly && <div className="row"><button className="btn sm primary" onClick={() => onEdit({ kind: 'aset', row: { required: 0, sort: data.alignment_sets.length + 1 } })}>+ เพิ่มชุดความสอดคล้อง</button></div>}
      {data.alignment_sets.map(s => {
        const items = data.alignment_items.filter(i => +i.alignment_set_id === +s.id);
        const rows = [];
        items.filter(i => !i.parent_id).forEach(p => { rows.push({ ...p, depth: 0 }); items.filter(c => +c.parent_id === +p.id).forEach(c => rows.push({ ...c, depth: 1 })); });
        return (
          <div key={s.id} className="card">
            <div className="card-h">
              <div className="row"><h3>{s.name}</h3><span className="xs mono muted">{s.code}</span>{+s.required ? <Badge bg="var(--orange-bg)" fg="var(--orange-fg)">บังคับเลือก (BR-03)</Badge> : null}</div>
              {!readOnly && <div className="row"><button className="btn sm" onClick={() => onEdit({ kind: 'aset', row: s })}>แก้ไขชุด</button><button className="btn sm primary" onClick={() => onEdit({ kind: 'aitem', row: { alignment_set_id: s.id, active: 1, sort: rows.length + 1 } })}>+ รายการ</button></div>}
            </div>
            {rows.map(r => (
              <div key={r.id} className="row" style={{ padding: '8px 18px', paddingLeft: 18 + r.depth * 24, borderBottom: '1px solid var(--row-line)', opacity: +r.active ? 1 : .5, fontSize: 13 }}>
                <span className="grow">{r.code ? <span className="mono xs muted">{r.code} </span> : null}{r.label}</span>
                {!readOnly && <button className="btn sm" onClick={() => onEdit({ kind: 'aitem', row: r })}>แก้ไข</button>}
              </div>
            ))}
          </div>
        );
      })}
    </div>
  );
}

function ChainSettings({ data, readOnly, save }) {
  const { toast } = useApp();
  return (
    <div className="stack">
      <Alert tone="blue">สายอนุมัติจะถูกใช้เมื่อเปิดระยะที่ 2–4 (ขออนุญาต ปรับแผน เสนอโครงการ) · ถ้าผู้ขอดำรงบทบาทของขั้นใด ระบบข้ามขั้นนั้นอัตโนมัติ</Alert>
      {data.chains.map(c => <ChainEditor key={c.id} chain={c} roleLabels={data.role_labels} readOnly={readOnly}
        onSave={steps => save('settings/chain_save', { id: c.id, steps }, 'บันทึกสายอนุมัติแล้ว').catch(e => toast(e.message, 'err'))} />)}
    </div>
  );
}
function ChainEditor({ chain, roleLabels, readOnly, onSave }) {
  const [steps, setSteps] = useState(chain.steps.map(s => ({ role: s.role, scope: s.scope, can_return: !!+s.can_return, can_reject: !!+s.can_reject })));
  const move = (i, d) => { const s = [...steps]; const [x] = s.splice(i, 1); s.splice(i + d, 0, x); setSteps(s); };
  const upd = (i, k, v) => setSteps(steps.map((s, j) => j === i ? { ...s, [k]: v } : s));
  return (
    <div className="card">
      <div className="card-h"><h3>{chain.name}</h3><span className="xs mono muted">{chain.request_type}</span></div>
      <div className="card-b stack" style={{ gap: 8 }}>
        {steps.map((s, i) => (
          <div key={i} className="row wrap" style={{ gap: 8, padding: 8, border: '1px solid var(--line)', borderRadius: 8 }}>
            <b style={{ width: 22 }}>{i + 1}.</b>
            <select className="select" disabled={readOnly} value={s.role} onChange={e => upd(i, 'role', e.target.value)}>
              {Object.entries(roleLabels).filter(([k]) => k !== 'admin').map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
            <select className="select" disabled={readOnly} value={s.scope} onChange={e => upd(i, 'scope', e.target.value)}>
              {Object.entries(SCOPE_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
            <label className="check sm"><input type="checkbox" disabled={readOnly} checked={s.can_return} onChange={e => upd(i, 'can_return', e.target.checked)} />ส่งกลับได้</label>
            <label className="check sm"><input type="checkbox" disabled={readOnly} checked={s.can_reject} onChange={e => upd(i, 'can_reject', e.target.checked)} />ไม่อนุมัติได้</label>
            {!readOnly && <div className="row" style={{ marginLeft: 'auto', gap: 4 }}>
              <button className="btn sm" disabled={i === 0} onClick={() => move(i, -1)} title="เลื่อนขึ้น">↑</button>
              <button className="btn sm" disabled={i === steps.length - 1} onClick={() => move(i, 1)} title="เลื่อนลง">↓</button>
              <button className="btn sm danger" disabled={steps.length === 1} onClick={() => setSteps(steps.filter((_, j) => j !== i))}>ลบ</button>
            </div>}
          </div>
        ))}
        {!readOnly && <div className="row" style={{ justifyContent: 'space-between' }}>
          <button className="btn sm" onClick={() => setSteps([...steps, { role: 'planner', scope: 'global', can_return: true, can_reject: false }])}>+ เพิ่มขั้น</button>
          <button className="btn sm primary" onClick={() => onSave(steps)}>บันทึกสายอนุมัติ</button>
        </div>}
      </div>
    </div>
  );
}

/** One modal for every master-data form. */
function SettingsEditor({ edit, data, onClose, save }) {
  const [f, setF] = useState({ ...edit.row });
  const [err, setErr] = useState('');
  const [busy, run] = useBusy();
  const set = (k, v) => setF(x => ({ ...x, [k]: v }));
  const submit = (path, body) => run(async () => { setErr(''); try { await save(path, body); } catch (e) { setErr(e.message); } });
  const units = buildUnits(data.units);
  const fundTree = buildFunds(data.funds.map(x => ({ ...x, color: x.color_token })));
  let title = '', body = null, action = null;

  if (edit.kind === 'fy') {
    const w = (f.settings && f.settings.weights) || {};
    const wSum = Object.values(w).reduce((a, b) => a + (+b || 0), 0);
    title = f.id ? 'แก้ไขปีงบประมาณ ' + f.year_be : 'เพิ่มปีงบประมาณ';
    body = <Fragment>
      {!f.id && <div className="form-grid">
        <Field label="ปีงบประมาณ (พ.ศ.)" required hint="1 ต.ค. ปีก่อน – 30 ก.ย."><input className="input" type="number" value={f.year_be || ''} onChange={e => set('year_be', e.target.value)} /></Field>
        <Field label="คัดลอกข้อมูลหลักจากปี" hint="แหล่งเงิน ความสอดคล้อง สายอนุมัติ">
          <select className="select" value={f.copy_from || ''} onChange={e => set('copy_from', e.target.value)}><option value="">ไม่คัดลอก</option>{data.fiscal_years.map(y => <option key={y.id} value={y.id}>{y.year_be}</option>)}</select>
        </Field>
      </div>}
      <Field label="สถานะ"><select className="select" value={f.status} onChange={e => set('status', e.target.value)}>{Object.entries(FY_STATUS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
      <div className="form-grid">
        <Field label="เปิดรับข้อเสนอตั้งแต่"><ThaiDateInput value={f.proposal_open_from || ''} onChange={v => set('proposal_open_from', v)} /></Field>
        <Field label="ถึง"><ThaiDateInput value={f.proposal_open_to || ''} onChange={v => set('proposal_open_to', v)} /></Field>
      </div>
      <div className="sm" style={{ fontWeight: 600 }}>น้ำหนักคะแนนเริ่มต้น <span style={{ color: wSum === 100 ? 'var(--green-fg)' : 'var(--red-text)' }}>(รวม {wSum}/100)</span></div>
      <div className="form-grid">{Object.keys(WEIGHT_LABEL).map(k => (
        <Field key={k} label={WEIGHT_LABEL[k]}><input className="input num" type="number" min="0" max="100" value={w[k] ?? ''} onChange={e => set('settings', { ...f.settings, weights: { ...w, [k]: +e.target.value } })} /></Field>
      ))}</div>
      <div className="form-grid">
        <Field label="กำหนดส่งรายงานผล (วันหลังเสร็จ)"><input className="input num" type="number" min="1" value={(f.settings.health || {}).report_due_days || 15} onChange={e => set('settings', { ...f.settings, health: { report_due_days: +e.target.value } })} /></Field>
        <Field label="โหมดเปรียบเทียบการปรับแผน (BR-51)">
          <select className="select" value={f.settings.adjustment_compare_mode || 'per_fund'} onChange={e => set('settings', { ...f.settings, adjustment_compare_mode: e.target.value })}>
            <option value="per_fund">แยกรายกองเงิน (per_fund)</option><option value="total">ยอดรวม (total)</option>
          </select>
        </Field>
      </div>
    </Fragment>;
    action = () => submit('settings/fiscal_year_save', f);
  }
  if (edit.kind === 'unit') {
    title = f.id ? 'แก้ไขหน่วยงาน' : 'เพิ่มหน่วยงาน';
    body = <Fragment>
      <Field label="ชื่อ" required><input className="input" value={f.name || ''} onChange={e => set('name', e.target.value)} /></Field>
      <div className="form-grid">
        <Field label="ประเภท"><select className="select" value={f.kind} onChange={e => set('kind', e.target.value)}>{Object.entries(UNIT_KIND).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
        <Field label="รหัส"><input className="input" value={f.code || ''} onChange={e => set('code', e.target.value)} /></Field>
      </div>
      <Field label="อยู่ภายใต้"><select className="select" value={f.parent_id || ''} onChange={e => set('parent_id', e.target.value)}>
        <option value="">— ระดับบนสุด —</option>{units.flat.filter(u => u.id !== f.id).map(u => <option key={u.id} value={u.id}>{'  '.repeat(u.depth)}{u.name}</option>)}
      </select></Field>
      <div className="form-grid">
        <Field label="ผู้เรียน ปวช." hint="ใช้เทียบสัดส่วนในชุดพิจารณา"><input className="input num" type="number" min="0" value={f.student_count_vc ?? ''} onChange={e => set('student_count_vc', e.target.value)} /></Field>
        <Field label="ผู้เรียน ปวส."><input className="input num" type="number" min="0" value={f.student_count_hvc ?? ''} onChange={e => set('student_count_hvc', e.target.value)} /></Field>
        <Field label="ลำดับ"><input className="input num" type="number" value={f.sort ?? 0} onChange={e => set('sort', e.target.value)} /></Field>
      </div>
      <label className="check"><input type="checkbox" checked={!!+f.active} onChange={e => set('active', e.target.checked ? 1 : 0)} />ใช้งาน</label>
    </Fragment>;
    action = () => submit('settings/unit_save', f);
  }
  if (edit.kind === 'fund') {
    title = f.id ? 'แก้ไขแหล่งเงิน' : 'เพิ่มแหล่งเงิน';
    body = <Fragment>
      <div className="form-grid">
        <Field label="ชื่อ" required><input className="input" value={f.name || ''} onChange={e => set('name', e.target.value)} /></Field>
        <Field label="รหัส" required><input className="input" value={f.code || ''} onChange={e => set('code', e.target.value)} /></Field>
      </div>
      <Field label="อยู่ภายใต้"><select className="select" value={f.parent_id || ''} onChange={e => set('parent_id', e.target.value)}>
        <option value="">— ระดับบนสุด —</option>{Object.values(fundTree.byId).filter(x => x.id !== f.id).map(x => <option key={x.id} value={x.id}>{fundTree.label(x.id)}</option>)}
      </select></Field>
      <div className="form-grid">
        <Field label="ประเภท"><select className="select" value={f.fund_type || ''} onChange={e => set('fund_type', e.target.value)}><option value="">— กลุ่ม (ไม่มีประเภท) —</option>{Object.entries(FUND_TYPE_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
        <Field label="กฎตรวจเงินก่อนอนุญาต (BR-31)"><select className="select" value={f.permit_rule || ''} onChange={e => set('permit_rule', e.target.value)}><option value="">—</option>{Object.entries(PERMIT_RULE_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
        <Field label="สีประจำกอง"><div className="row"><input type="color" value={f.color_token || '#8A96A8'} onChange={e => set('color_token', e.target.value)} /><input className="input mono" value={f.color_token || ''} onChange={e => set('color_token', e.target.value)} placeholder="สืบทอดจากกองแม่" /></div></Field>
        <Field label="ลำดับ"><input className="input num" type="number" value={f.sort ?? 0} onChange={e => set('sort', e.target.value)} /></Field>
      </div>
      <label className="check"><input type="checkbox" checked={!!+f.active} onChange={e => set('active', e.target.checked ? 1 : 0)} />ใช้งาน</label>
    </Fragment>;
    action = () => submit('settings/fund_save', f);
  }
  if (edit.kind === 'fund_cats') {
    const leaves = data.categories.filter(c => c.parent_id);
    const ids = f.allowed_category_ids || [];
    title = 'หมวดที่อนุญาต: ' + f.name;
    body = <Fragment>
      <div className="sm muted">ไม่เลือกเลย = ใช้ได้ทุกหมวด</div>
      {data.categories.filter(c => !c.parent_id).map(t => (
        <div key={t.id}><div className="sm" style={{ fontWeight: 600, margin: '4px 0' }}>{t.name}</div>
          <div className="row wrap" style={{ gap: '4px 16px' }}>{leaves.filter(c => +c.parent_id === +t.id).map(c => (
            <label key={c.id} className="check sm"><input type="checkbox" checked={ids.includes(+c.id)} onChange={e => set('allowed_category_ids', e.target.checked ? [...ids, +c.id] : ids.filter(x => x !== +c.id))} />{c.name}</label>
          ))}</div></div>
      ))}
    </Fragment>;
    action = () => submit('settings/fund_categories', { fund_source_id: f.id, category_ids: ids });
  }
  if (edit.kind === 'category') {
    title = f.id ? 'แก้ไขหมวดรายจ่าย' : 'เพิ่มหมวดรายจ่าย';
    body = <Fragment>
      <div className="form-grid">
        <Field label="ชื่อ" required><input className="input" value={f.name || ''} onChange={e => set('name', e.target.value)} /></Field>
        <Field label="รหัส" required><input className="input" value={f.code || ''} onChange={e => set('code', e.target.value)} /></Field>
      </div>
      <Field label="อยู่ภายใต้หมวด"><select className="select" value={f.parent_id || ''} onChange={e => set('parent_id', e.target.value)}>
        <option value="">— หมวดหลัก —</option>{data.categories.filter(c => !c.parent_id && c.id !== f.id).map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
      </select></Field>
      <Field label="ลำดับ"><input className="input num" type="number" value={f.sort ?? 0} onChange={e => set('sort', e.target.value)} /></Field>
      <label className="check"><input type="checkbox" checked={!!+f.active} onChange={e => set('active', e.target.checked ? 1 : 0)} />ใช้งาน</label>
    </Fragment>;
    action = () => submit('settings/category_save', f);
  }
  if (edit.kind === 'aset') {
    title = f.id ? 'แก้ไขชุดความสอดคล้อง' : 'เพิ่มชุดความสอดคล้อง';
    body = <Fragment>
      <div className="form-grid">
        <Field label="ชื่อ" required><input className="input" value={f.name || ''} onChange={e => set('name', e.target.value)} /></Field>
        <Field label="รหัส" required hint="a-z 0-9 _"><input className="input mono" value={f.code || ''} onChange={e => set('code', e.target.value)} /></Field>
        <Field label="ลำดับ"><input className="input num" type="number" value={f.sort ?? 0} onChange={e => set('sort', e.target.value)} /></Field>
      </div>
      <label className="check"><input type="checkbox" checked={!!+f.required} onChange={e => set('required', e.target.checked ? 1 : 0)} />บังคับเลือกอย่างน้อย 1 รายการ (BR-03)</label>
    </Fragment>;
    action = () => submit('settings/alignment_set_save', f);
  }
  if (edit.kind === 'aitem') {
    const siblings = data.alignment_items.filter(i => +i.alignment_set_id === +f.alignment_set_id && !i.parent_id && i.id !== f.id);
    title = f.id ? 'แก้ไขรายการความสอดคล้อง' : 'เพิ่มรายการความสอดคล้อง';
    body = <Fragment>
      <Field label="ข้อความ" required><textarea className="textarea" rows={2} value={f.label || ''} onChange={e => set('label', e.target.value)} /></Field>
      <div className="form-grid">
        <Field label="รหัส"><input className="input" value={f.code || ''} onChange={e => set('code', e.target.value)} /></Field>
        <Field label="อยู่ภายใต้"><select className="select" value={f.parent_id || ''} onChange={e => set('parent_id', e.target.value)}><option value="">— ระดับบน —</option>{siblings.map(s => <option key={s.id} value={s.id}>{s.label.slice(0, 60)}</option>)}</select></Field>
        <Field label="ลำดับ"><input className="input num" type="number" value={f.sort ?? 0} onChange={e => set('sort', e.target.value)} /></Field>
      </div>
      <label className="check"><input type="checkbox" checked={!!+f.active} onChange={e => set('active', e.target.checked ? 1 : 0)} />ใช้งาน</label>
    </Fragment>;
    action = () => submit('settings/alignment_item_save', f);
  }
  return (
    <Modal title={title} onClose={onClose} wide={edit.kind === 'fy' || edit.kind === 'fund_cats'} footer={<Fragment>
      <button className="btn" onClick={onClose}>ยกเลิก</button>
      <button className="btn primary" onClick={action} disabled={busy}>บันทึก</button>
    </Fragment>}>
      {body}
      {err && <Alert tone="red">{err}</Alert>}
    </Modal>
  );
}

Object.assign(window, { SettingsPage, GeneralSettings, UnitSettings, FundSettings, CategorySettings, AlignmentSettings, ChainSettings, ChainEditor, SettingsEditor });
