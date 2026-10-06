// admin.jsx — ผู้ดูแลระบบ: users & roles, database migrations, backups, audit log

// ------------------------------------------------------------------ users
function UsersPage() {
  const { meta, toast, units: unitTree, reloadMeta } = useApp();
  const { data, error, loading, reload } = useApi('users/index');
  const [q, setQ] = useState('');
  const [edit, setEdit] = useState(null);
  const [roles, setRoles] = useState(null);
  const [pwd, setPwd] = useState(null);
  if (!meta.permissions.admin) return <Forbidden />;
  const users = data ? data.users.filter(u => !q || (u.username + ' ' + u.name + ' ' + (u.email || '')).toLowerCase().includes(q.toLowerCase())) : [];
  return (
    <div className="stack">
      <PageHead crumb="ผู้ดูแลระบบ" title="ผู้ใช้และบทบาท">
        <button className="btn primary" onClick={() => setEdit({ active: 1 })}>+ เพิ่มผู้ใช้</button>
      </PageHead>
      <Alert tone="blue">บทบาทผูกกับ (ผู้ใช้, บทบาท, หน่วยงาน, ปีงบประมาณ) — ผู้ใช้หนึ่งคนมีได้หลายบทบาท · ผู้เสนอ / หัวหน้างาน / รองฯ ฝ่าย ต้องระบุหน่วยงานเพื่อกำหนดขอบเขตข้อมูล · ผู้ดูแลระบบไม่มีสิทธิ์อนุมัติหรือบันทึกเงิน</Alert>
      <div className="card">
        <div className="row" style={{ padding: '12px 16px' }}>
          <div className="search"><Icon name="search" size={16} /><input value={q} onChange={e => setQ(e.target.value)} placeholder="ค้นหาชื่อผู้ใช้ ชื่อ หรืออีเมล" /></div>
          <span className="sm muted">{users.length} ผู้ใช้</span>
        </div>
        {error && <div style={{ padding: 16 }}><LoadError error={error} onRetry={reload} /></div>}
        {loading && !data && <div className="card-b"><div className="skel" style={{ height: 14 }} /></div>}
        <div className="table-wrap" style={{ borderTop: '1px solid var(--line)' }}><table className="tbl" style={{ minWidth: 900 }}>
          <thead><tr><th>ผู้ใช้</th><th>ตำแหน่ง</th><th>บทบาท</th><th>เข้าระบบล่าสุด</th><th>สถานะ</th><th /></tr></thead>
          <tbody>{users.map(u => (
            <tr key={u.id} style={{ opacity: +u.active ? 1 : .55 }}>
              <td><div style={{ fontWeight: 500 }}>{u.name}</div><div className="xs mono muted">{u.username}{u.email ? ' · ' + u.email : ''}</div></td>
              <td className="sm">{u.position_title || '—'}</td>
              <td className="sm">{u.roles.length === 0 ? <span className="muted">ไม่มีบทบาท</span> : u.roles.map((r, i) => (
                <div key={i}>{data.role_labels[r.role]}{r.unit_name ? <span className="muted"> · {r.unit_name}</span> : ''}<span className="xs muted"> · {r.year_be ? 'ปี ' + r.year_be : 'ทุกปี'}</span></div>
              ))}</td>
              <td className="sm nowrap">{u.last_login_at ? thDate(u.last_login_at, true) : '—'}</td>
              <td className="sm">{+u.active ? 'ใช้งาน' : 'ปิดใช้งาน'}</td>
              <td className="nowrap">
                <button className="btn sm" onClick={() => setEdit(u)}>แก้ไข</button>{' '}
                <button className="btn sm" onClick={() => setRoles(u)}>บทบาท</button>{' '}
                <button className="btn sm" onClick={() => setPwd(u)}>รีเซ็ตรหัสผ่าน</button>
              </td>
            </tr>
          ))}</tbody>
        </table></div>
      </div>
      {edit && <UserEditor user={edit} onClose={() => setEdit(null)} onSaved={() => { setEdit(null); reload(); }} />}
      {roles && <RoleEditor user={roles} labels={data.role_labels} scoped={data.unit_scoped_roles} onClose={() => setRoles(null)} onSaved={() => { setRoles(null); reload(); reloadMeta(); }} />}
      {pwd && <ResetPassword user={pwd} onClose={() => setPwd(null)} />}
    </div>
  );
}

function UserEditor({ user, onClose, onSaved }) {
  const { toast } = useApp();
  const [f, setF] = useState({ ...user, password: '' });
  const [err, setErr] = useState('');
  const [busy, run] = useBusy();
  const save = () => run(async () => {
    setErr('');
    try { await post('users/save', f); toast('บันทึกผู้ใช้แล้ว', 'ok'); onSaved(); } catch (e) { setErr(e.message); }
  });
  return (
    <Modal title={user.id ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้'} onClose={onClose} footer={<Fragment>
      <button className="btn" onClick={onClose}>ยกเลิก</button><button className="btn primary" onClick={save} disabled={busy}>บันทึก</button>
    </Fragment>}>
      <div className="form-grid">
        <Field label="ชื่อผู้ใช้" required><input className="input" value={f.username || ''} onChange={e => setF({ ...f, username: e.target.value })} autoComplete="off" /></Field>
        <Field label="ชื่อ-สกุล" required><input className="input" value={f.name || ''} onChange={e => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="อีเมล"><input className="input" type="email" value={f.email || ''} onChange={e => setF({ ...f, email: e.target.value })} /></Field>
        <Field label="ตำแหน่ง"><input className="input" value={f.position_title || ''} onChange={e => setF({ ...f, position_title: e.target.value })} /></Field>
      </div>
      {!user.id && <Field label="รหัสผ่านเริ่มต้น" required hint="อย่างน้อย 8 ตัวอักษร"><input className="input" type="password" value={f.password} onChange={e => setF({ ...f, password: e.target.value })} autoComplete="new-password" /></Field>}
      <label className="check"><input type="checkbox" checked={!!+f.active} onChange={e => setF({ ...f, active: e.target.checked ? 1 : 0 })} />ใช้งานได้</label>
      {err && <Alert tone="red">{err}</Alert>}
    </Modal>
  );
}

function RoleEditor({ user, labels, scoped, onClose, onSaved }) {
  const { meta, units, toast } = useApp();
  const [list, setList] = useState(user.roles.map(r => ({ role: r.role, org_unit_id: r.org_unit_id || '', fiscal_year_id: r.fiscal_year_id || '' })));
  const [err, setErr] = useState('');
  const [busy, run] = useBusy();
  const upd = (i, k, v) => setList(list.map((r, j) => j === i ? { ...r, [k]: v } : r));
  const save = () => run(async () => {
    setErr('');
    try { await post('users/roles', { user_id: user.id, roles: list }); toast('บันทึกบทบาทแล้ว', 'ok'); onSaved(); } catch (e) { setErr(e.message); }
  });
  return (
    <Modal wide title={'บทบาทของ ' + user.name} onClose={onClose} footer={<Fragment>
      <button className="btn" onClick={onClose}>ยกเลิก</button><button className="btn primary" onClick={save} disabled={busy}>บันทึกบทบาท</button>
    </Fragment>}>
      {list.length === 0 && <div className="sm muted">ยังไม่มีบทบาท</div>}
      {list.map((r, i) => (
        <div key={i} className="row wrap" style={{ gap: 8, padding: 8, border: '1px solid var(--line)', borderRadius: 8 }}>
          <select className="select" value={r.role} onChange={e => upd(i, 'role', e.target.value)} style={{ minWidth: 220 }}>
            {Object.entries(labels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
          {r.role !== 'admin' && (
            <select className={'select' + (scoped.includes(r.role) && !r.org_unit_id ? ' err' : '')} value={r.org_unit_id} onChange={e => upd(i, 'org_unit_id', e.target.value ? +e.target.value : '')} style={{ flex: 1, minWidth: 200 }}>
              <option value="">{scoped.includes(r.role) ? '— เลือกหน่วยงาน (จำเป็น) —' : 'ทุกหน่วยงาน'}</option>
              {units.flat.map(u => <option key={u.id} value={u.id}>{'  '.repeat(u.depth)}{u.name}</option>)}
            </select>
          )}
          {r.role !== 'admin' && (
            <select className="select" value={r.fiscal_year_id} onChange={e => upd(i, 'fiscal_year_id', e.target.value ? +e.target.value : '')}>
              <option value="">ทุกปีงบประมาณ</option>{meta.fiscal_years.map(f => <option key={f.id} value={f.id}>ปี {f.year_be}</option>)}
            </select>
          )}
          <button className="btn sm danger" onClick={() => setList(list.filter((_, j) => j !== i))}>ลบ</button>
        </div>
      ))}
      <div><button className="btn sm" onClick={() => setList([...list, { role: 'proposer', org_unit_id: '', fiscal_year_id: meta.fy }])}>+ เพิ่มบทบาท</button></div>
      {err && <Alert tone="red">{err}</Alert>}
    </Modal>
  );
}

function ResetPassword({ user, onClose }) {
  const { toast } = useApp();
  const [p, setP] = useState('');
  const [err, setErr] = useState('');
  const [busy, run] = useBusy();
  const gen = () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    const a = new Uint32Array(12); crypto.getRandomValues(a);
    setP(Array.from(a, x => chars[x % chars.length]).join(''));
  };
  const save = () => run(async () => {
    setErr('');
    try { await post('users/reset_password', { user_id: user.id, password: p }); toast('รีเซ็ตรหัสผ่านของ ' + user.username + ' แล้ว', 'ok'); onClose(); } catch (e) { setErr(e.message); }
  });
  return (
    <Modal title={'รีเซ็ตรหัสผ่าน: ' + user.username} onClose={onClose} footer={<Fragment>
      <button className="btn" onClick={onClose}>ยกเลิก</button><button className="btn primary" onClick={save} disabled={busy}>บันทึก</button>
    </Fragment>}>
      <Field label="รหัสผ่านใหม่" hint="อย่างน้อย 8 ตัวอักษร · แจ้งผู้ใช้ให้เปลี่ยนหลังเข้าระบบ">
        <div className="row"><input className="input mono grow" value={p} onChange={e => setP(e.target.value)} autoComplete="off" /><button className="btn" onClick={gen}>สุ่ม</button></div>
      </Field>
      {err && <Alert tone="red">{err}</Alert>}
    </Modal>
  );
}

// ------------------------------------------------------------------ institutions
// Single mode: the system admin switches the system to multi-institution mode (and becomes the central admin).
// Multi mode: the central admin manages institutions and their admins.
function InstitutionsPage() {
  const { meta } = useApp();
  if (!meta.permissions.institutions) return <Forbidden />;
  return meta.tenancy.mode === 'multi' ? <InstitutionList /> : <EnableMulti />;
}

function EnableMulti() {
  const { meta, toast } = useApp();
  const [f, setF] = useState({ system_name: 'ระบบแผนงานและงบประมาณสถานศึกษา', password: '', confirm: false });
  const [err, setErr] = useState('');
  const [busy, run] = useBusy();
  const go = () => run(async () => {
    setErr('');
    try {
      const r = await post('system/enable_multi', f);
      toast('เปิดใช้งานแบบหลายสถานศึกษาแล้ว' + (r.institution_has_admin ? '' : ' — อย่าลืมกำหนดผู้ดูแลระบบให้ ' + meta.org_name), 'ok');
      try { sessionStorage.removeItem('vecplan_fy'); } catch (e) { }
      window.location.hash = '#/institutions';
      window.location.reload();
    } catch (e) { setErr(e.message); }
  });
  return (
    <div className="stack">
      <PageHead crumb="ผู้ดูแลระบบ" title="สถานศึกษา" />
      <div className="card card-b metric-list">
        <div><span>รูปแบบการใช้งาน</span><span>สถานศึกษาเดียว</span></div>
        <div><span>สถานศึกษา</span><span>{meta.org_name}</span></div>
      </div>
      <div className="card card-b stack" style={{ gap: 12 }}>
        <h3 style={{ margin: 0 }}>เปิดใช้งานแบบหลายสถานศึกษา</h3>
        <div className="sm">ให้สถานศึกษาหลายแห่งใช้ระบบนี้ร่วมกัน โดยข้อมูลของแต่ละแห่งแยกจากกัน</div>
        <ul className="sm" style={{ margin: 0, paddingLeft: 20, lineHeight: 1.8 }}>
          <li>ข้อมูลที่มีอยู่ทั้งหมดจะเป็นของ <b>{meta.org_name}</b> (สถานศึกษาแรก)</li>
          <li>บัญชีของคุณ (<span className="mono">{meta.user.username}</span>) จะกลายเป็น <b>ผู้ดูแลระบบกลาง</b> — ไม่สังกัดสถานศึกษาใด และจะไม่เห็นข้อมูลงบประมาณของสถานศึกษาอีก บทบาทเดิมทั้งหมดของบัญชีนี้จะถูกถอดออก</li>
          <li>ผู้ดูแลระบบกลางสร้างสถานศึกษาและผู้ดูแลระบบสถานศึกษา รัน migrations และสำรองข้อมูล</li>
          <li>ผู้ดูแลระบบสถานศึกษาจัดการผู้ใช้และบทบาทได้เฉพาะในสถานศึกษาของตัวเอง</li>
          <li>หลังเปิดแล้วให้กำหนดผู้ดูแลระบบสถานศึกษาให้ {meta.org_name} จากหน้านี้ — ย้อนกลับเป็นสถานศึกษาเดียวจากหน้าจอไม่ได้</li>
        </ul>
        <div className="form-grid">
          <Field label="ชื่อระบบ (แสดงที่หน้าเข้าสู่ระบบ)" required><input className="input" value={f.system_name} onChange={e => setF({ ...f, system_name: e.target.value })} /></Field>
          <Field label="ยืนยันด้วยรหัสผ่านของคุณ" required><input className="input" type="password" value={f.password} onChange={e => setF({ ...f, password: e.target.value })} autoComplete="current-password" /></Field>
        </div>
        <label className="check"><input type="checkbox" checked={f.confirm} onChange={e => setF({ ...f, confirm: e.target.checked })} />เข้าใจแล้วว่าบัญชีนี้จะกลายเป็นผู้ดูแลระบบกลาง</label>
        {err && <Alert tone="red">{err}</Alert>}
        <div><button className="btn primary" disabled={busy || !f.confirm || !f.password} onClick={go}>{busy ? 'กำลังเปิดใช้งาน…' : 'เปิดใช้งานแบบหลายสถานศึกษา'}</button></div>
      </div>
    </div>
  );
}

function InstitutionList() {
  const { toast } = useApp();
  const { data, error, loading, reload } = useApi('institutions/index');
  const [edit, setEdit] = useState(null);
  const [admin, setAdmin] = useState(null);
  const [sysName, setSysName] = useState(null);
  const [busy, run] = useBusy();
  const list = data ? data.institutions : [];
  const saveName = () => run(async () => {
    try { await post('institutions/system_name', { system_name: sysName }); toast('บันทึกชื่อระบบแล้ว', 'ok'); setSysName(null); reload(); } catch (e) { toast(e.message, 'err'); }
  });
  return (
    <div className="stack">
      <PageHead crumb="ผู้ดูแลระบบกลาง" title="สถานศึกษา">
        <button className="btn primary" onClick={() => setEdit({ active: 1, year_be: data ? data.default_year_be : '' })}>+ เพิ่มสถานศึกษา</button>
      </PageHead>
      <Alert tone="blue">ข้อมูลของแต่ละสถานศึกษาแยกจากกัน (ปีงบประมาณ หน่วยงาน แหล่งเงิน โครงการ สมุดบัญชี ผู้ใช้) · ผู้ดูแลระบบสถานศึกษาจัดการผู้ใช้และบทบาทในสถานศึกษาของตัวเอง · ชื่อผู้ใช้ต้องไม่ซ้ำกันทั้งระบบ · ปิดใช้งานสถานศึกษาแล้วผู้ใช้ของแห่งนั้นเข้าระบบไม่ได้</Alert>
      {data && (
        <div className="card card-b row wrap" style={{ gap: 8 }}>
          <span className="sm muted">ชื่อระบบ</span>
          {sysName === null
            ? <Fragment><b>{data.system_name}</b><button className="btn sm" onClick={() => setSysName(data.system_name)}>แก้ไข</button></Fragment>
            : <Fragment><input className="input grow" value={sysName} onChange={e => setSysName(e.target.value)} style={{ minWidth: 240 }} />
                <button className="btn sm primary" disabled={busy} onClick={saveName}>บันทึก</button><button className="btn sm" onClick={() => setSysName(null)}>ยกเลิก</button></Fragment>}
        </div>
      )}
      {error && <LoadError error={error} onRetry={reload} />}
      <div className="card">
        {loading && !data && <div className="card-b"><div className="skel" style={{ height: 14 }} /></div>}
        <div className="table-wrap"><table className="tbl" style={{ minWidth: 860 }}>
          <thead><tr><th>รหัส</th><th>สถานศึกษา</th><th>ผู้ดูแลระบบสถานศึกษา</th><th className="th-num">ผู้ใช้</th><th className="th-num">ปีงบ</th><th className="th-num">โครงการ</th><th>สถานะ</th><th /></tr></thead>
          <tbody>{list.map(i => (
            <tr key={i.id} style={{ opacity: +i.active ? 1 : .55 }}>
              <td className="mono sm">{i.code}</td>
              <td style={{ fontWeight: 500 }}>{i.name}</td>
              <td className="sm">{i.admins.length === 0 ? <Badge bg="var(--orange-bg)" fg="var(--orange-fg)">ยังไม่มีผู้ดูแล</Badge> : i.admins.map(a => (
                <div key={a.id}>{a.name} <span className="xs mono muted">{a.username}</span>{!+a.active && <span className="xs muted"> (ปิดใช้งาน)</span>}</div>
              ))}</td>
              <td className="num sm">{fmt0(+i.active_user_count)}{+i.user_count !== +i.active_user_count && <span className="xs muted">/{i.user_count}</span>}</td>
              <td className="num sm">{i.fiscal_year_count}</td>
              <td className="num sm">{fmt0(+i.project_count)}</td>
              <td className="sm">{+i.active ? 'ใช้งาน' : 'ปิดใช้งาน'}</td>
              <td className="nowrap">
                <button className="btn sm" onClick={() => setEdit(i)}>แก้ไข</button>{' '}
                <button className="btn sm" onClick={() => setAdmin({ institution: i })}>{i.admins.length ? 'ผู้ดูแล / รีเซ็ตรหัสผ่าน' : 'กำหนดผู้ดูแล'}</button>
              </td>
            </tr>
          ))}</tbody>
        </table></div>
      </div>
      {edit && <InstitutionEditor inst={edit} onClose={() => setEdit(null)} onSaved={() => { setEdit(null); reload(); }} />}
      {admin && <InstitutionAdminEditor inst={admin.institution} onClose={() => setAdmin(null)} onSaved={() => { setAdmin(null); reload(); }} />}
    </div>
  );
}

function genPassword() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
  const a = new Uint32Array(12); crypto.getRandomValues(a);
  return Array.from(a, x => chars[x % chars.length]).join('');
}

function InstitutionEditor({ inst, onClose, onSaved }) {
  const { toast } = useApp();
  const isNew = !inst.id;
  const [f, setF] = useState({ id: inst.id, code: inst.code || '', name: inst.name || '', active: inst.active ?? 1, year_be: inst.year_be || '' });
  const [a, setA] = useState({ username: '', name: '', email: '', password: '' });
  const [err, setErr] = useState('');
  const [busy, run] = useBusy();
  const save = () => run(async () => {
    setErr('');
    try {
      await post('institutions/save', isNew ? { ...f, admin: a } : f);
      toast(isNew ? 'สร้าง ' + f.name + ' แล้ว — แจ้งชื่อผู้ใช้และรหัสผ่านให้ผู้ดูแลระบบสถานศึกษา' : 'บันทึกแล้ว', 'ok');
      onSaved();
    } catch (e) { setErr(e.message); }
  });
  return (
    <Modal wide title={isNew ? 'เพิ่มสถานศึกษา' : 'แก้ไขสถานศึกษา'} onClose={onClose} footer={<Fragment>
      <button className="btn" onClick={onClose}>ยกเลิก</button><button className="btn primary" onClick={save} disabled={busy}>{isNew ? 'สร้างสถานศึกษา' : 'บันทึก'}</button>
    </Fragment>}>
      <div className="form-grid">
        <Field label="รหัสสถานศึกษา" required hint="A-Z 0-9 _ - เช่น รหัสสถานศึกษาของ สอศ."><input className="input mono" value={f.code} onChange={e => setF({ ...f, code: e.target.value.toUpperCase() })} /></Field>
        <Field label="ชื่อสถานศึกษา" required><input className="input" value={f.name} onChange={e => setF({ ...f, name: e.target.value })} /></Field>
        {isNew && <Field label="ปีงบประมาณแรก (พ.ศ.)" required hint="สร้างพร้อมข้อมูลตั้งต้น: หน่วยงาน แหล่งเงิน หมวดรายจ่าย ความสอดคล้อง สายอนุมัติ">
          <input className="input" type="number" value={f.year_be} onChange={e => setF({ ...f, year_be: e.target.value })} /></Field>}
      </div>
      {!isNew && <label className="check"><input type="checkbox" checked={!!+f.active} onChange={e => setF({ ...f, active: e.target.checked ? 1 : 0 })} />ใช้งานได้ (ปิดแล้วผู้ใช้ของสถานศึกษานี้เข้าระบบไม่ได้)</label>}
      {isNew && (
        <Fragment>
          <div style={{ fontWeight: 600, marginTop: 4 }}>ผู้ดูแลระบบสถานศึกษา</div>
          <div className="form-grid">
            <Field label="ชื่อผู้ใช้" required><input className="input" value={a.username} onChange={e => setA({ ...a, username: e.target.value })} autoComplete="off" /></Field>
            <Field label="ชื่อ-สกุล" required><input className="input" value={a.name} onChange={e => setA({ ...a, name: e.target.value })} /></Field>
            <Field label="อีเมล"><input className="input" type="email" value={a.email} onChange={e => setA({ ...a, email: e.target.value })} /></Field>
            <Field label="รหัสผ่านเริ่มต้น" required hint="อย่างน้อย 8 ตัวอักษร">
              <div className="row"><input className="input mono grow" value={a.password} onChange={e => setA({ ...a, password: e.target.value })} autoComplete="off" /><button className="btn" onClick={() => setA({ ...a, password: genPassword() })}>สุ่ม</button></div>
            </Field>
          </div>
        </Fragment>
      )}
      {err && <Alert tone="red">{err}</Alert>}
    </Modal>
  );
}

function InstitutionAdminEditor({ inst, onClose, onSaved }) {
  const { toast } = useApp();
  const first = inst.admins[0];
  const [f, setF] = useState({ institution_id: inst.id, username: first ? first.username : '', name: first ? first.name : '', email: first ? (first.email || '') : '', password: '' });
  const [err, setErr] = useState('');
  const [busy, run] = useBusy();
  const pick = a => setF({ ...f, username: a.username, name: a.name, email: a.email || '' });
  const save = () => run(async () => {
    setErr('');
    try { await post('institutions/admin_save', f); toast('บันทึกผู้ดูแลระบบของ ' + inst.name + ' แล้ว', 'ok'); onSaved(); } catch (e) { setErr(e.message); }
  });
  return (
    <Modal title={'ผู้ดูแลระบบ: ' + inst.name} onClose={onClose} footer={<Fragment>
      <button className="btn" onClick={onClose}>ยกเลิก</button><button className="btn primary" onClick={save} disabled={busy}>บันทึก</button>
    </Fragment>}>
      <div className="sm muted">ใช้ชื่อผู้ใช้ของผู้ดูแลเดิมเพื่อรีเซ็ตรหัสผ่าน หรือกรอกชื่อผู้ใช้ใหม่เพื่อเพิ่มผู้ดูแลอีกคน</div>
      {inst.admins.length > 0 && <div className="row wrap" style={{ gap: 6 }}>{inst.admins.map(a => <button key={a.id} className="btn sm" onClick={() => pick(a)}>{a.username}</button>)}</div>}
      <div className="form-grid">
        <Field label="ชื่อผู้ใช้" required><input className="input" value={f.username} onChange={e => setF({ ...f, username: e.target.value })} autoComplete="off" /></Field>
        <Field label="ชื่อ-สกุล" required><input className="input" value={f.name} onChange={e => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="อีเมล"><input className="input" type="email" value={f.email} onChange={e => setF({ ...f, email: e.target.value })} /></Field>
      </div>
      <Field label="รหัสผ่าน" required hint="อย่างน้อย 8 ตัวอักษร · แจ้งผู้ดูแลให้เปลี่ยนหลังเข้าระบบ">
        <div className="row"><input className="input mono grow" value={f.password} onChange={e => setF({ ...f, password: e.target.value })} autoComplete="off" /><button className="btn" onClick={() => setF({ ...f, password: genPassword() })}>สุ่ม</button></div>
      </Field>
      {err && <Alert tone="red">{err}</Alert>}
    </Modal>
  );
}

// ------------------------------------------------------------------ migrations
function MigrationsPage() {
  const { meta, toast } = useApp();
  const { data, error, loading, reload } = useApi('system/migrations');
  const [busy, run] = useBusy();
  const [backup, setBackup] = useState(true);
  const [results, setResults] = useState(null);
  const [view, setView] = useState(null);
  const [create, setCreate] = useState(false);
  const [confirm, setConfirm] = useState(null);
  if (!meta.permissions.system) return <Forbidden />;
  const act = (path, body, label) => run(async () => {
    setConfirm(null);
    try {
      const r = await post(path, { ...body, backup });
      setResults({ label, ...r });
      toast(r.ok === false ? label + ' ไม่สำเร็จ' : label + ' สำเร็จ', r.ok === false ? 'err' : 'ok');
    } catch (e) { toast(e.message, 'err'); setResults({ label, error: e.message }); }
    reload();
  });
  const show = name => run(async () => { try { setView(await api('system/migration_source', { query: { name } })); } catch (e) { toast(e.message, 'err'); } });
  const list = data ? data.migrations : [];
  const lastBatch = Math.max(0, ...list.filter(m => m.applied).map(m => m.batch || 0));

  return (
    <div className="stack">
      <PageHead crumb="ผู้ดูแลระบบ" title="Migrations ฐานข้อมูล">
        <button className="btn" onClick={() => setCreate(true)} disabled={!data || !data.writable} title={data && !data.writable ? 'โฟลเดอร์ migrations เขียนไม่ได้' : ''}>+ สร้าง migration</button>
        <button className="btn" disabled={busy || !lastBatch} onClick={() => setConfirm({ kind: 'rollback' })}><Icon name="undo" size={16} />ย้อน batch ล่าสุด</button>
        <button className="btn primary" disabled={busy || !data || !data.pending} onClick={() => setConfirm({ kind: 'migrate' })}><Icon name="play" size={16} />รันที่ค้างทั้งหมด ({data ? data.pending : 0})</button>
      </PageHead>
      <div className="card card-b row wrap" style={{ gap: '8px 24px' }}>
        <label className="check"><input type="checkbox" checked={backup} onChange={e => setBackup(e.target.checked)} />สำรองฐานข้อมูลอัตโนมัติก่อนรัน/ย้อน (แนะนำ)</label>
        <span className="sm muted">ไฟล์อยู่ในโฟลเดอร์ <span className="mono">migrations/</span> · ชื่อไฟล์ <span className="mono">YYYY_MM_DD_HHMMSS_name.sql</span> มีส่วน <span className="mono">-- @up</span> และ <span className="mono">-- @down</span></span>
      </div>
      {data && data.pending > 0 && <Alert tone="orange" title={'มี migration ที่ยังไม่ได้รัน ' + data.pending + ' รายการ'}>โครงสร้างฐานข้อมูลอาจไม่ตรงกับโค้ดเวอร์ชันนี้ ควรรันก่อนใช้งาน</Alert>}
      {data && data.pending === 0 && <Alert tone="green">โครงสร้างฐานข้อมูลเป็นปัจจุบัน</Alert>}
      {results && (
        <div className="card card-b stack" style={{ gap: 8 }}>
          <div className="row" style={{ justifyContent: 'space-between' }}><b>ผล{results.label}</b><button className="btn sm" onClick={() => setResults(null)}>ปิด</button></div>
          {results.error && <Alert tone="red">{results.error}</Alert>}
          {results.backup && <div className="sm muted">สำรองข้อมูลก่อนดำเนินการ: <span className="mono">{results.backup.file}</span></div>}
          {(results.results || []).map(r => <div key={r.name} className="sm row" style={{ alignItems: 'flex-start' }}>
            <span style={{ color: r.ok ? 'var(--green-fg)' : 'var(--red-text)', fontWeight: 700 }}>{r.ok ? '✓' : '✗'}</span>
            <span className="mono">{r.name}</span>{r.duration_ms !== undefined && <span className="xs muted">{r.duration_ms} ms</span>}
            {r.error && <span style={{ color: 'var(--red-text)' }}>{r.error}</span>}
          </div>)}
          {results.results && results.results.length === 0 && <div className="sm muted">ไม่มีรายการที่ต้องดำเนินการ</div>}
        </div>
      )}
      {error && <LoadError error={error} onRetry={reload} />}
      <div className="card">
        {loading && !data && <div className="card-b"><div className="skel" style={{ height: 14 }} /></div>}
        <div className="table-wrap"><table className="tbl" style={{ minWidth: 900 }}>
          <thead><tr><th>Migration</th><th>คำอธิบาย</th><th>สถานะ</th><th className="th-num">Batch</th><th>รันเมื่อ</th><th /></tr></thead>
          <tbody>{list.map(m => (
            <tr key={m.name}>
              <td className="mono xs" style={{ wordBreak: 'break-all' }}>{m.name}</td>
              <td className="sm">{m.description || '—'}</td>
              <td className="nowrap">
                {m.missing ? <Badge bg="var(--red-bg)" fg="var(--red-text)">ไม่พบไฟล์</Badge> : m.applied ? <Badge bg="var(--green-bg)" fg="var(--green-fg)">รันแล้ว</Badge> : <Badge bg="var(--yellow-bg)" fg="var(--yellow-fg)">รอรัน</Badge>}
                {m.modified && <div><Badge bg="var(--orange-bg)" fg="var(--orange-fg)" title="ไฟล์ถูกแก้ไขหลังรัน">ไฟล์ถูกแก้ไข</Badge></div>}
              </td>
              <td className="num sm">{m.batch || '—'}</td>
              <td className="sm nowrap">{m.applied_at ? thDate(m.applied_at, true) : '—'}{m.applied_by ? <div className="xs muted">{m.applied_by} · {m.duration_ms} ms</div> : null}</td>
              <td className="nowrap">
                {!m.missing && <button className="btn sm" onClick={() => show(m.name)}>ดู SQL</button>}{' '}
                {!m.applied && <button className="btn sm primary" disabled={busy} onClick={() => setConfirm({ kind: 'one', name: m.name })}>รัน</button>}{' '}
                {m.applied && m.has_down && <button className="btn sm" disabled={busy} onClick={() => setConfirm({ kind: 'down', name: m.name })}>ย้อน</button>}{' '}
                <button className="btn sm link" disabled={busy} onClick={() => setConfirm({ kind: 'mark', name: m.name, applied: !m.applied })}>{m.applied ? 'ทำเครื่องหมายว่ายังไม่รัน' : 'ทำเครื่องหมายว่ารันแล้ว'}</button>
              </td>
            </tr>
          ))}</tbody>
        </table></div>
      </div>
      {view && <Modal wide title={view.name} onClose={() => setView(null)}><pre className="code">{view.source}</pre></Modal>}
      {create && <CreateMigration onClose={() => setCreate(false)} onSaved={() => { setCreate(false); reload(); }} />}
      {confirm && (() => {
        const c = confirm;
        const text = {
          migrate: ['รัน migration ที่ค้างทั้งหมด', 'ระบบจะรัน ' + (data ? data.pending : 0) + ' ไฟล์ตามลำดับเป็น batch ใหม่ ถ้าไฟล์ใดล้มเหลวจะหยุดที่ไฟล์นั้น'],
          rollback: ['ย้อน batch ล่าสุด (batch ' + lastBatch + ')', 'รันส่วน @down ของทุกไฟล์ใน batch ล่าสุด — ตารางหรือคอลัมน์ที่ถูกลบจะทำให้ข้อมูลในนั้นหายไป'],
          one: ['รัน ' + c.name, 'รันเฉพาะไฟล์นี้เป็น batch ใหม่'],
          down: ['ย้อน ' + c.name, 'รันส่วน @down ของไฟล์นี้ ข้อมูลที่อยู่ในโครงสร้างที่ถูกลบจะหายไป'],
          mark: [c.applied ? 'ทำเครื่องหมายว่ารันแล้ว' : 'ทำเครื่องหมายว่ายังไม่รัน', 'บันทึกสถานะเท่านั้น ไม่รัน SQL — ใช้เมื่อปรับโครงสร้างด้วยมือไปแล้ว'],
        }[c.kind];
        const go = () => c.kind === 'migrate' ? act('system/migrate', {}, 'การรัน migration')
          : c.kind === 'rollback' ? act('system/rollback', {}, 'การย้อน migration')
          : c.kind === 'one' ? act('system/migrate', { name: c.name }, 'การรัน ' + c.name)
          : c.kind === 'down' ? act('system/rollback', { name: c.name }, 'การย้อน ' + c.name)
          : act('system/mark', { name: c.name, applied: c.applied }, 'การเปลี่ยนสถานะ');
        return (
          <Modal title={text[0]} onClose={() => setConfirm(null)} footer={<Fragment>
            <button className="btn" onClick={() => setConfirm(null)}>ยกเลิก</button>
            <button className={'btn ' + (c.kind === 'rollback' || c.kind === 'down' ? 'warn' : 'primary')} onClick={go} disabled={busy}>ยืนยัน</button>
          </Fragment>}>
            <div className="sm">{text[1]}</div>
            {c.kind !== 'mark' && <div className="sm muted">{backup ? 'จะสำรองฐานข้อมูลก่อนดำเนินการ' : 'ไม่ได้เลือกสำรองข้อมูลก่อนดำเนินการ'}</div>}
          </Modal>
        );
      })()}
    </div>
  );
}

function CreateMigration({ onClose, onSaved }) {
  const { toast } = useApp();
  const [f, setF] = useState({ slug: '', description: '', up: '', down: '' });
  const [err, setErr] = useState('');
  const [busy, run] = useBusy();
  const save = () => run(async () => {
    setErr('');
    try { const r = await post('system/create_migration', f); toast('สร้าง ' + r.name + ' แล้ว (ยังไม่ได้รัน)', 'ok'); onSaved(); } catch (e) { setErr(e.message); }
  });
  return (
    <Modal wide title="สร้าง migration ใหม่" onClose={onClose} footer={<Fragment>
      <button className="btn" onClick={onClose}>ยกเลิก</button><button className="btn primary" onClick={save} disabled={busy}>สร้างไฟล์</button>
    </Fragment>}>
      <div className="form-grid">
        <Field label="ชื่อ (a-z 0-9 _)" required hint="ระบบเติมวันเวลาหน้าชื่อไฟล์ให้"><input className="input mono" value={f.slug} onChange={e => setF({ ...f, slug: e.target.value })} placeholder="add_contract_no_to_projects" /></Field>
        <Field label="คำอธิบาย"><input className="input" value={f.description} onChange={e => setF({ ...f, description: e.target.value })} /></Field>
      </div>
      <Field label="SQL ส่วน up" required hint="คั่นคำสั่งด้วย ; · ข้อมูลใน ledger_entries แก้/ลบไม่ได้ (append-only)">
        <textarea className="textarea code" rows={8} value={f.up} onChange={e => setF({ ...f, up: e.target.value })} placeholder="ALTER TABLE projects ADD COLUMN contract_no VARCHAR(60) NULL;" />
      </Field>
      <Field label="SQL ส่วน down (สำหรับย้อนกลับ)"><textarea className="textarea code" rows={5} value={f.down} onChange={e => setF({ ...f, down: e.target.value })} placeholder="ALTER TABLE projects DROP COLUMN contract_no;" /></Field>
      <Alert tone="orange">อย่าลืมแก้โค้ดที่เกี่ยวข้องและ commit ไฟล์ migration เข้า git เพื่อให้เครื่องอื่นได้รับการเปลี่ยนแปลงเดียวกัน</Alert>
      {err && <Alert tone="red">{err}</Alert>}
    </Modal>
  );
}

// ------------------------------------------------------------------ backups
function BackupsPage() {
  const { meta, toast } = useApp();
  const { data, error, reload } = useApi('system/backups');
  const info = useApi('system/info');
  const [busy, run] = useBusy();
  const [creating, setCreating] = useState(false);
  if (!meta.permissions.system) return <Forbidden />;
  const del = f => run(async () => { if (!confirm('ลบไฟล์สำรอง ' + f + '?')) return; try { await post('system/backup_delete', { file: f }); reload(); } catch (e) { toast(e.message, 'err'); } });
  const i = info.data;
  return (
    <div className="stack">
      <PageHead crumb="ผู้ดูแลระบบ" title="สำรองข้อมูล">
        <button className="btn primary" onClick={() => setCreating(true)} disabled={!data}>สำรองข้อมูลตอนนี้</button>
      </PageHead>
      {creating && data && <CreateBackup years={data.fiscal_years} onClose={() => setCreating(false)} onDone={() => { setCreating(false); reload(); }} />}
      {i && (
        <div className="card card-b metric-list">
          <div><span>เวอร์ชันระบบ</span><span>{i.app_version}</span></div>
          <div><span>PHP</span><span>{i.php_version}</span></div>
          <div><span>ฐานข้อมูล</span><span>{i.db_version}</span></div>
          <div><span>ชื่อฐานข้อมูล</span><span className="mono">{i.db_name}</span></div>
          <div><span>ขนาด</span><span>{i.db_size_mb} MB</span></div>
          <div><span>ติดตั้งเมื่อ</span><span>{i.installed ? thDate(i.installed.installed_at.slice(0, 10)) : '—'}</span></div>
        </div>
      )}
      <Alert tone="blue">ไฟล์สำรองเป็น SQL (gzip) รวมโครงสร้าง ข้อมูล และ trigger ของสมุดบัญชี เก็บใน <span className="mono">storage/backups/</span> — ควรคัดลอกออกไปเก็บนอกเซิร์ฟเวอร์ และสำรองโฟลเดอร์ <span className="mono">uploads/</span> ด้วย · กู้คืนด้วยคำสั่ง <span className="mono">gunzip -c ไฟล์ | mysql -u … ชื่อฐานข้อมูล</span>
        · ไฟล์สำรองรายปีงบประมาณกู้คืนได้ในฐานข้อมูลว่างเท่านั้น (ไม่มีคำสั่งลบตาราง จึงไม่ทับข้อมูลปีอื่นในฐานข้อมูลที่ใช้งานอยู่)</Alert>
      {error && <LoadError error={error} onRetry={reload} />}
      <div className="card">
        <div className="table-wrap"><table className="tbl">
          <thead><tr><th>ไฟล์</th><th>ขอบเขต</th><th>สร้างเมื่อ</th><th className="th-num">ขนาด</th><th /></tr></thead>
          <tbody>
            {data && data.backups.map(b => (
              <tr key={b.file}><td className="mono xs">{b.file}</td>
                <td className="sm">{b.year_scoped ? <Badge bg="var(--blue-bg)" fg="var(--blue-fg)">{b.scope}</Badge> : b.scope}</td>
                <td className="sm">{thDate(b.created_at, true)}</td><td className="num sm">{(b.size / 1024).toFixed(1)} KB</td>
                <td className="nowrap"><a className="btn sm" href={apiUrl('system/backup_download', { file: b.file })}>ดาวน์โหลด</a>{' '}<button className="btn sm danger" onClick={() => del(b.file)}>ลบ</button></td></tr>
            ))}
            {data && data.backups.length === 0 && <tr><td colSpan={5} className="sm muted">ยังไม่มีไฟล์สำรอง</td></tr>}
          </tbody>
        </table></div>
      </div>
    </div>
  );
}

// Choose what to back up: the whole database, or selected fiscal years of one institution.
function CreateBackup({ years, onClose, onDone }) {
  const { toast } = useApp();
  const institutions = [];
  years.forEach(y => { if (!institutions.some(i => i.id === y.institution_id)) institutions.push({ id: y.institution_id, name: y.institution_name, code: y.institution_code }); });
  const [scope, setScope] = useState('full');
  const [inst, setInst] = useState(institutions[0] ? institutions[0].id : null);
  const [picked, setPicked] = useState([]);
  const [err, setErr] = useState('');
  const [busy, run] = useBusy();
  const instYears = years.filter(y => y.institution_id === inst);
  const toggle = id => setPicked(picked.includes(id) ? picked.filter(x => x !== id) : [...picked, id]);
  const go = () => run(async () => {
    setErr('');
    try {
      const r = await post('system/backup', scope === 'years' ? { fiscal_year_ids: picked } : {});
      toast('สร้าง ' + r.file + ' แล้ว', 'ok');
      onDone();
    } catch (e) { setErr(e.message); }
  });
  return (
    <Modal title="สำรองข้อมูล" onClose={onClose} footer={<Fragment>
      <button className="btn" onClick={onClose}>ยกเลิก</button>
      <button className="btn primary" onClick={go} disabled={busy || (scope === 'years' && !picked.length)}>{busy ? 'กำลังสำรอง…' : 'สำรองข้อมูล'}</button>
    </Fragment>}>
      <label className="check"><input type="radio" checked={scope === 'full'} onChange={() => setScope('full')} />
        <span><b>ทั้งระบบ</b><div className="xs muted">ทุกตาราง ทุกสถานศึกษา ทุกปีงบประมาณ — กู้คืนทับฐานข้อมูลเดิมได้</div></span></label>
      <label className="check"><input type="radio" checked={scope === 'years'} onChange={() => setScope('years')} disabled={!years.length} />
        <span><b>เลือกปีงบประมาณ</b><div className="xs muted">เฉพาะข้อมูลของปีที่เลือก (แหล่งเงิน ประมาณการ โครงการ สมุดบัญชี ความสอดคล้อง สายอนุมัติ) พร้อมข้อมูลหลักที่อ้างถึง (หน่วยงาน หมวดรายจ่าย ผู้ใช้) — กู้คืนได้ในฐานข้อมูลว่างเท่านั้น</div></span></label>
      {scope === 'years' && (
        <div className="stack" style={{ gap: 8, paddingLeft: 24 }}>
          {institutions.length > 1 && (
            <Field label="สถานศึกษา">
              <select className="select" value={inst || ''} onChange={e => { setInst(+e.target.value); setPicked([]); }}>
                {institutions.map(i => <option key={i.id} value={i.id}>{i.name} ({i.code})</option>)}
              </select>
            </Field>
          )}
          <div className="row wrap" style={{ gap: '6px 16px' }}>
            {instYears.map(y => (
              <label key={y.id} className="check"><input type="checkbox" checked={picked.includes(y.id)} onChange={() => toggle(y.id)} />ปีงบประมาณ {y.year_be}</label>
            ))}
          </div>
          <div className="xs muted">ไฟล์แนบในโฟลเดอร์ <span className="mono">uploads/</span> ไม่รวมอยู่ในไฟล์สำรอง</div>
        </div>
      )}
      {err && <Alert tone="red">{err}</Alert>}
    </Modal>
  );
}

// ------------------------------------------------------------------ audit log
function AuditPage() {
  const { meta } = useApp();
  const [action, setAction] = useState('');
  const [page, setPage] = useState(1);
  const { data, error, reload } = useApi('system/audit', { action, page });
  if (!meta.permissions.audit) return <Forbidden />;
  const central = !!meta.permissions.super_admin;
  return (
    <div className="stack">
      <PageHead crumb="ผู้ดูแลระบบ" title="บันทึกการใช้งาน (audit log)" />
      <div className="card">
        <div className="row wrap" style={{ padding: '12px 16px' }}>
          <select className="select" value={action} onChange={e => { setAction(e.target.value); setPage(1); }}>
            <option value="">ทุกการกระทำ</option>
            {['auth', 'ledger', 'estimate', 'project', 'plan', 'baseline', 'user', 'migration', 'backup', 'settings', 'fund_source', 'org_unit', 'fiscal_year', 'institution', 'system', 'install'].map(a => <option key={a} value={a}>{a}.*</option>)}
          </select>
          <span className="sm muted">{data ? fmt0(data.total) + ' รายการ' : ''}</span>
        </div>
        {error && <div style={{ padding: 16 }}><LoadError error={error} onRetry={reload} /></div>}
        <div className="table-wrap" style={{ borderTop: '1px solid var(--line)' }}><table className="tbl dense" style={{ minWidth: 900 }}>
          <thead><tr><th>เวลา</th>{central && <th>สถานศึกษา</th>}<th>ผู้ใช้</th><th>การกระทำ</th><th>เป้าหมาย</th><th>รายละเอียด</th><th>IP</th></tr></thead>
          <tbody>{data && data.rows.map(r => (
            <tr key={r.id}>
              <td className="sm nowrap">{thDate(r.created_at, true)}</td>
              {central && <td className="sm">{r.institution_name || <span className="muted">ระบบกลาง</span>}</td>}
              <td className="sm">{r.user_name || 'ระบบ'}</td>
              <td className="mono xs">{r.action}</td><td className="xs">{r.subject_type ? r.subject_type + ' #' + (r.subject_id || '') : '—'}</td>
              <td className="xs mono muted" style={{ maxWidth: 420, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={r.after || ''}>{r.after || r.before || ''}</td>
              <td className="xs mono">{r.ip}</td>
            </tr>
          ))}</tbody>
        </table></div>
        {data && <div className="pager"><span>หน้า {data.page}</span><Pager page={data.page} per={100} total={data.total} onPage={setPage} /></div>}
      </div>
    </div>
  );
}

Object.assign(window, { UsersPage, UserEditor, RoleEditor, ResetPassword, InstitutionsPage, MigrationsPage, CreateMigration, BackupsPage, CreateBackup, AuditPage });
