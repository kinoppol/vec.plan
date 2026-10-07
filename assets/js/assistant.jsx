// assistant.jsx — floating AI assistant (chat panel, confirmation of actions, voice input) and its settings page

const AI_TOOL_LABELS = {
  get_dashboard: 'ดูภาพรวมหน้าหลัก', get_reference_data: 'ดูข้อมูลอ้างอิง', list_projects: 'ค้นหาโครงการ', get_project: 'ดูรายละเอียดโครงการ',
  get_fund_positions: 'ดูสถานะกองเงิน', list_revenue_estimates: 'ดูประมาณการรายรับ', list_ledger_entries: 'ค้นหาสมุดบัญชี',
  get_ledger_entry: 'ดูรายการบัญชี', list_users: 'ดูรายชื่อผู้ใช้', get_audit_log: 'ดูบันทึกการใช้งาน', open_page: 'เปิดหน้า',
  post_ledger_entry: 'บันทึกรายการกองเงิน', reverse_ledger_entry: 'กลับรายการบัญชี', save_revenue_estimate: 'บันทึกประมาณการรายรับ',
  delete_revenue_estimate: 'ลบประมาณการรายรับ',
};
const SPEECH_ERRORS = {
  'not-allowed': 'เบราว์เซอร์ไม่อนุญาตให้ใช้ไมโครโฟน', 'service-not-allowed': 'เบราว์เซอร์ไม่อนุญาตให้ใช้บริการแปลงเสียง',
  'no-speech': 'ไม่ได้ยินเสียง ลองพูดอีกครั้ง', 'audio-capture': 'ไม่พบไมโครโฟน', network: 'บริการแปลงเสียงของเบราว์เซอร์เชื่อมต่อไม่ได้',
  'language-not-supported': 'เบราว์เซอร์นี้ยังไม่รองรับการแปลงเสียงภาษาไทย',
};

// ------------------------------------------------------------------ speech-to-text (Web Speech API of the browser)
function useSpeech(onText) {
  const Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
  const [listening, setListening] = useState(false);
  const [error, setError] = useState('');
  const rec = useRef(null);
  useEffect(() => () => { if (rec.current) rec.current.abort(); }, []);
  const start = base => {
    if (!Rec || rec.current) return;
    const r = new Rec();
    r.lang = 'th-TH';
    r.interimResults = true;
    r.continuous = false;
    let done = '';
    r.onresult = e => {
      let interim = '';
      for (let i = e.resultIndex; i < e.results.length; i++) {
        if (e.results[i].isFinal) done += e.results[i][0].transcript; else interim += e.results[i][0].transcript;
      }
      onText((base ? base.replace(/\s+$/, '') + ' ' : '') + (done + interim).trim());
    };
    r.onerror = e => { if (e.error !== 'aborted') setError(SPEECH_ERRORS[e.error] || 'แปลงเสียงไม่สำเร็จ (' + e.error + ')'); };
    r.onend = () => { rec.current = null; setListening(false); };
    try { r.start(); rec.current = r; setListening(true); setError(''); }
    catch (e) { setError('เริ่มรับเสียงไม่ได้'); }
  };
  const stop = () => { if (rec.current) rec.current.stop(); };
  return { supported: !!Rec, secure: window.isSecureContext !== false, listening, error, start, stop, clearError: () => setError('') };
}

// ------------------------------------------------------------------ light Markdown → React (no innerHTML)
function aiInline(text, key) {
  return String(text).split(/(\*\*[^*]+\*\*|`[^`]+`)/g).map((p, i) => {
    if (/^\*\*[^*]+\*\*$/.test(p)) return <b key={key + '-' + i}>{p.slice(2, -2)}</b>;
    if (/^`[^`]+`$/.test(p)) return <code key={key + '-' + i}>{p.slice(1, -1)}</code>;
    return p;
  });
}
function AiText({ text }) {
  const lines = String(text || '').replace(/\r/g, '').split('\n');
  const out = [];
  const cells = l => l.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(c => c.trim());
  for (let i = 0; i < lines.length;) {
    const l = lines[i];
    if (/^\s*\|.*\|\s*$/.test(l)) {
      const rows = [];
      while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) { rows.push(lines[i]); i++; }
      const body = rows.filter(r => !/^\s*\|?[\s:|-]+\|?\s*$/.test(r)).map(cells);
      const head = rows.length > 1 && /^\s*\|?[\s:|-]+\|?\s*$/.test(rows[1]) ? body.shift() : null;
      out.push(
        <div className="ai-table" key={'t' + i}><table>
          {head && <thead><tr>{head.map((c, j) => <th key={j}>{aiInline(c, 'h' + j)}</th>)}</tr></thead>}
          <tbody>{body.map((r, k) => <tr key={k}>{r.map((c, j) => <td key={j} className={/^[−-]?[\d,]+(\.\d+)?%?$/.test(c) ? 'num' : ''}>{aiInline(c, k + '-' + j)}</td>)}</tr>)}</tbody>
        </table></div>);
    } else if (/^\s*([-*•]|\d+[.)])\s+/.test(l)) {
      const ordered = /^\s*\d/.test(l);
      const items = [];
      while (i < lines.length && /^\s*([-*•]|\d+[.)])\s+/.test(lines[i])) { items.push(lines[i].replace(/^\s*([-*•]|\d+[.)])\s+/, '')); i++; }
      const Tag = ordered ? 'ol' : 'ul';
      out.push(<Tag key={'l' + i}>{items.map((t, j) => <li key={j}>{aiInline(t, 'li' + j)}</li>)}</Tag>);
    } else if (/^#{1,4}\s+/.test(l)) {
      out.push(<div className="ai-h" key={'h' + i}>{aiInline(l.replace(/^#+\s+/, ''), 'hd' + i)}</div>);
      i++;
    } else if (l.trim() === '') {
      i++;
    } else {
      const para = [];
      while (i < lines.length && lines[i].trim() !== '' && !/^\s*(\||[-*•]\s|\d+[.)]\s|#{1,4}\s)/.test(lines[i])) { para.push(lines[i]); i++; }
      out.push(<p key={'p' + i}>{para.map((t, j) => <Fragment key={j}>{j > 0 && <br />}{aiInline(t, 'p' + i + '-' + j)}</Fragment>)}</p>);
    }
  }
  return <div className="ai-text">{out}</div>;
}

// ------------------------------------------------------------------ floating widget
function aiSuggestions(perms) {
  const s = [];
  if (perms.view_funds) s.push('สรุปภาพรวมกองเงินปีนี้ให้หน่อย');
  if (perms.view_projects) s.push('โครงการไหนที่ยังไม่ได้ใช้เงินเลย');
  if (perms.view_ledger) s.push('รายการในสมุดบัญชี 10 รายการล่าสุด');
  if (perms.post_receipt) s.push('บันทึกรับเงินอุดหนุน 150,000 บาท');
  s.push('ฉันทำอะไรในระบบนี้ได้บ้าง');
  return s.slice(0, 4);
}

function AssistantWidget() {
  const { meta, refresh } = useApp();
  const info = meta.assistant || {};
  const storeKey = 'vecplan_ai_' + meta.user.id + '_' + meta.fy;
  const saved = useMemo(() => { try { return JSON.parse(sessionStorage.getItem(storeKey) || 'null') || {}; } catch (e) { return {}; } }, [storeKey]);
  const [open, setOpen] = useState(false);
  const [msgs, setMsgs] = useState(saved.msgs || []);
  const [pending, setPending] = useState(saved.pending || null);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const bodyRef = useRef(null);
  const inputRef = useRef(null);
  const speech = useSpeech(setInput);
  // Which API connection / model answers (when the admin enabled more than one); remembered per user.
  const pickKey = 'vecplan_ai_model_' + meta.user.id;
  const [pick, setPick] = useState(() => { try { return localStorage.getItem(pickKey) || ''; } catch (e) { return ''; } });

  useEffect(() => { setMsgs(saved.msgs || []); setPending(saved.pending || null); }, [storeKey]);
  useEffect(() => {
    try { sessionStorage.setItem(storeKey, JSON.stringify({ msgs: msgs.slice(-60), pending })); } catch (e) { /* storage full or blocked */ }
  }, [msgs, pending, storeKey]);
  useEffect(() => { if (bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight; }, [msgs, pending, busy, open]);
  useEffect(() => {
    if (!open) return;
    const k = e => { if (e.key === 'Escape') setOpen(false); };
    window.addEventListener('keydown', k);
    if (inputRef.current) inputRef.current.focus();
    return () => window.removeEventListener('keydown', k);
  }, [open]);

  if (!info.enabled) return null;

  const conns = info.connections || [];
  const choices = [];
  conns.forEach(c => c.models.forEach(m => choices.push({ value: c.id + '|' + m, conn: c, model: m })));
  const current = choices.find(o => o.value === pick) || choices.find(o => o.conn === conns[0] && o.model === conns[0].default_model) || choices[0];
  const choose = v => { setPick(v); try { localStorage.setItem(pickKey, v); } catch (e) { /* storage blocked */ } };

  const call = async body => {
    setBusy(true);
    setErr('');
    try {
      const route = parseHash();
      const r = await post('assistant/chat', { ...body, connection_id: current.conn.id, model: current.model, context: { page: route.page, params: route.params } });
      setMsgs(r.messages);
      setPending(r.pending && r.pending.length ? r.pending : null);
      (r.actions || []).forEach(a => { if (a.type === 'navigate') navigate(a.page, a.params); });
      if (r.changed) refresh();
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(false);
    }
  };
  const send = text => {
    const t = String(text || '').trim();
    if (!t || busy) return;
    if (speech.listening) speech.stop();
    const next = [...msgs, { role: 'user', content: t }];
    setMsgs(next);
    setInput('');
    setPending(null);
    call({ messages: next });
  };
  const decide = ok => {
    if (!pending || busy) return;
    const decisions = {};
    pending.forEach(p => { decisions[p.id] = ok; });
    setPending(null);
    call({ messages: msgs, decisions });
  };
  const retry = () => { if (msgs.length) call({ messages: msgs }); };
  const reset = () => { if (speech.listening) speech.stop(); setMsgs([]); setPending(null); setErr(''); setInput(''); };
  const onKey = e => {
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) { e.preventDefault(); send(input); }
  };
  const lastIsUser = msgs.length > 0 && msgs[msgs.length - 1].role === 'user';
  const rows = Math.min(5, Math.max(1, input.split('\n').length));

  return (
    <Fragment>
      {!open && (
        <button className="ai-fab" onClick={() => setOpen(true)} title="ผู้ช่วย AI" aria-label="เปิดผู้ช่วย AI">
          <Icon name="sparkle" size={24} stroke={1.8} />
          {pending && <span className="ai-fab-dot" />}
        </button>
      )}
      {open && (
        <section className="ai-panel" role="dialog" aria-label="ผู้ช่วย AI">
          <header className="ai-head">
            <div className="ai-avatar"><Icon name="sparkle" size={18} /></div>
            <div className="grow" style={{ minWidth: 0 }}>
              <div className="ai-title">ผู้ช่วย AI</div>
              {choices.length > 1 ? (
                <select className="ai-model-select" value={current.value} onChange={e => choose(e.target.value)} disabled={busy}
                  aria-label="เลือก API และโมเดล" title="เลือก API / โมเดลที่ใช้ตอบ">
                  {conns.map(c => <optgroup key={c.id} label={c.name}>{c.models.map(m => <option key={m} value={c.id + '|' + m}>{m}</option>)}</optgroup>)}
                </select>
              ) : <div className="xs muted ai-model" title={current.model}>{current.conn.name} · {current.model}</div>}
            </div>
            <button className="icon-btn plain" onClick={reset} title="เริ่มบทสนทนาใหม่" aria-label="เริ่มบทสนทนาใหม่" disabled={busy}><Icon name="newChat" size={17} /></button>
            <button className="icon-btn plain" onClick={() => setOpen(false)} title="ปิด (Esc)" aria-label="ปิด"><Icon name="x" size={17} /></button>
          </header>

          <div className="ai-body" ref={bodyRef} aria-live="polite">
            {msgs.length === 0 && (
              <div className="ai-welcome">
                <div className="ai-welcome-ic"><Icon name="sparkle" size={26} /></div>
                <div style={{ fontWeight: 600, color: 'var(--heading)' }}>สวัสดีครับ คุณ{meta.user.name.split(/\s+/)[0]}</div>
                <div className="sm muted">ถามข้อมูลแผนงาน โครงการ และกองเงินได้เลย หรือสั่งให้ช่วยบันทึกข้อมูลตามสิทธิ์ของคุณ — ทุกการบันทึกจะให้คุณยืนยันก่อนเสมอ</div>
                <div className="ai-suggest">
                  {aiSuggestions(meta.permissions).map(s => <button key={s} className="ai-chip-btn" onClick={() => send(s)}>{s}</button>)}
                </div>
              </div>
            )}
            {msgs.map((m, i) => {
              if (m.role === 'user') return <div key={i} className="ai-msg user">{m.content}</div>;
              if (m.role !== 'assistant') return null;
              return (
                <Fragment key={i}>
                  {m.tool_calls && m.tool_calls.length > 0 && (
                    <div className="ai-steps">{m.tool_calls.map(tc => (
                      <span key={tc.id} className="ai-step"><Icon name="check" size={12} />{AI_TOOL_LABELS[tc.function.name] || tc.function.name}</span>
                    ))}</div>
                  )}
                  {m.content && <div className="ai-msg bot"><AiText text={m.content} /></div>}
                </Fragment>
              );
            })}
            {pending && (
              <div className="ai-confirm">
                <div className="row" style={{ gap: 8, fontWeight: 600, color: 'var(--orange-fg)' }}><Icon name="warn" size={17} />ยืนยันก่อนดำเนินการ</div>
                {pending.map(p => (
                  <div key={p.id} className="ai-confirm-item">
                    <div style={{ fontWeight: 600, color: 'var(--heading)' }}>{p.title}</div>
                    <table><tbody>{p.fields.map(([k, v], j) => <tr key={j}><th>{k}</th><td>{v}</td></tr>)}</tbody></table>
                  </div>
                ))}
                <div className="row" style={{ justifyContent: 'flex-end', gap: 8 }}>
                  <button className="btn sm" onClick={() => decide(false)} disabled={busy}>ไม่อนุญาต</button>
                  <button className="btn sm primary" onClick={() => decide(true)} disabled={busy}>ยืนยันดำเนินการ</button>
                </div>
              </div>
            )}
            {busy && <div className="ai-msg bot ai-typing" aria-label="กำลังคิด"><span /><span /><span /></div>}
            {err && (
              <div className="ai-error">
                <div>{err}</div>
                {lastIsUser && <button className="btn sm" onClick={retry} disabled={busy}>ลองอีกครั้ง</button>}
              </div>
            )}
          </div>

          <footer className="ai-foot">
            {speech.error && <div className="ai-hint err" onClick={speech.clearError}>{speech.error}</div>}
            {speech.listening && <div className="ai-hint"><span className="ai-rec" />กำลังฟัง… พูดได้เลย</div>}
            <div className="ai-input">
              <textarea ref={inputRef} rows={rows} value={input} onChange={e => setInput(e.target.value)} onKeyDown={onKey}
                placeholder={speech.listening ? 'กำลังฟัง…' : 'พิมพ์คำถาม หรือกดไมค์เพื่อพูด'} aria-label="ข้อความถึงผู้ช่วย AI" maxLength={8000} />
              {speech.supported && (
                <button className={'icon-btn plain ai-mic' + (speech.listening ? ' on' : '')} disabled={!speech.secure}
                  onClick={() => speech.listening ? speech.stop() : speech.start(input)}
                  title={!speech.secure ? 'การพูดต้องเปิดระบบผ่าน HTTPS หรือ localhost' : speech.listening ? 'หยุดฟัง' : 'พูดแทนการพิมพ์'}
                  aria-label={speech.listening ? 'หยุดฟัง' : 'พูดแทนการพิมพ์'}>
                  <Icon name={speech.listening ? 'stop' : 'mic'} size={18} />
                </button>
              )}
              <button className="icon-btn ai-send" onClick={() => send(input)} disabled={busy || !input.trim()} title="ส่ง (Enter)" aria-label="ส่ง">
                <Icon name="send" size={17} />
              </button>
            </div>
            <div className="xs faint" style={{ textAlign: 'center' }}>AI อาจตอบผิดพลาดได้ ตรวจสอบตัวเลขสำคัญก่อนใช้งาน</div>
          </footer>
        </section>
      )}
    </Fragment>
  );
}

// ------------------------------------------------------------------ settings page (institution admin)
function AssistantSettingsPage() {
  const { meta, toast, reloadMeta } = useApp();
  const { data, error, loading, reload } = useApi('assistant/connections');
  const [edit, setEdit] = useState(null);
  const [opts, setOpts] = useState(null);
  const [busy, run] = useBusy();
  useEffect(() => { if (data) setOpts(data.options); }, [data]);
  if (!meta.permissions.ai_config) return <Forbidden />;
  if (error) return <LoadError error={error} onRetry={reload} />;
  if (loading || !data || !opts) return <Skeleton rows={6} />;

  const P = data.providers;
  const saved = () => { setEdit(null); reload(); reloadMeta(); };
  const remove = c => run(async () => {
    if (!window.confirm('ลบการเชื่อมต่อ "' + c.name + '"? ผู้ใช้จะเลือก API นี้ไม่ได้อีก')) return;
    try { await post('assistant/connection_delete', { id: c.id }); toast('ลบการเชื่อมต่อแล้ว', 'ok'); saved(); }
    catch (e) { toast(e.message, 'err'); }
  });
  const toggle = c => run(async () => {
    try { await post('assistant/connection_save', { ...c, enabled: !c.enabled }); toast(c.enabled ? 'ปิดใช้งาน ' + c.name : 'เปิดใช้งาน ' + c.name, 'ok'); saved(); }
    catch (e) { toast(e.message, 'err'); }
  });
  const saveOpts = () => run(async () => {
    try { await post('assistant/options_save', opts); toast('บันทึกตัวเลือกผู้ช่วยแล้ว', 'ok'); reload(); reloadMeta(); }
    catch (e) { toast(e.message, 'err'); }
  });
  const active = data.connections.filter(c => c.enabled && c.models.length);

  return (
    <div className="stack">
      <PageHead crumb="ผู้ดูแลระบบ" title="ผู้ช่วย AI">
        <button className="btn primary" onClick={() => setEdit({ provider: 'openrouter', base_url: P.openrouter.base_url, enabled: true, models: [], temperature: 0.3 })}>+ เพิ่มการเชื่อมต่อ API</button>
      </PageHead>
      {meta.tenancy && meta.tenancy.mode === 'multi' && <Alert tone="blue">การเชื่อมต่อ API ของแต่ละสถานศึกษาแยกจากกัน — ค่าในหน้านี้ใช้เฉพาะ {meta.org_name}</Alert>}
      {!data.curl && <Alert tone="yellow">PHP ไม่ได้เปิด extension curl — ระบบจะเชื่อมต่อผ่าน stream แทน ถ้าเชื่อมต่อ HTTPS ไม่ได้ให้เปิด extension=curl ใน php.ini</Alert>}
      <Alert tone={active.length ? 'green' : 'yellow'}>
        {active.length
          ? 'ผู้ใช้เห็นปุ่มผู้ช่วย AI แล้ว · เปิดใช้งาน ' + active.length + ' การเชื่อมต่อ รวม ' + active.reduce((n, c) => n + c.models.length, 0) + ' โมเดล' + (active.length > 1 || active[0].models.length > 1 ? ' — ผู้ใช้เลือกได้ว่าจะใช้ตัวไหนในหน้าต่างสนทนา' : '')
          : 'ยังไม่มีการเชื่อมต่อที่เปิดใช้งาน ผู้ใช้จึงยังไม่เห็นปุ่มผู้ช่วย AI — เพิ่มการเชื่อมต่อ ทดสอบ แล้วเลือกโมเดลที่จะเปิดใช้งาน'}
      </Alert>

      <div className="ai-settings-grid">
        <div className="stack">
          <div className="card">
            <div className="card-h"><h3>การเชื่อมต่อ API</h3><span className="sm muted">{data.connections.length} รายการ</span></div>
            {data.connections.length === 0 ? (
              <Empty icon="sparkle" title="ยังไม่มีการเชื่อมต่อ">เชื่อมต่อ OpenRouter, Google AI Studio, OpenAI หรือ LLM server ภายในองค์กร (Ollama, LM Studio) ได้หลายรายการพร้อมกัน</Empty>
            ) : (
              <div className="ai-conn-list">{data.connections.map(c => (
                <div key={c.id} className={'ai-conn' + (c.enabled ? '' : ' off')}>
                  <div className="grow" style={{ minWidth: 0 }}>
                    <div className="row" style={{ gap: 8, flexWrap: 'wrap' }}>
                      <b style={{ color: 'var(--heading)' }}>{c.name}</b>
                      {c.enabled && c.models.length ? <Badge bg="var(--green-bg)" fg="var(--green-fg)">เปิดใช้งาน</Badge>
                        : c.enabled ? <Badge bg="var(--yellow-bg)" fg="var(--yellow-fg)">ยังไม่ได้เลือกโมเดล</Badge>
                        : <Badge bg="var(--gray-bg)" fg="var(--gray-fg)">ปิด</Badge>}
                    </div>
                    <div className="xs muted" style={{ marginTop: 2 }}>{(P[c.provider] || {}).label} · <span className="mono">{c.base_url}</span>{c.has_key ? ' · คีย์ ' + c.key_hint : ''}</div>
                    <div className="ai-model-tags">{c.models.map(m => <span key={m} className={'ai-tag' + (m === c.default_model ? ' def' : '')} title={m === c.default_model ? 'โมเดลเริ่มต้น' : ''}>{m}</span>)}</div>
                  </div>
                  <div className="row" style={{ gap: 6, flexShrink: 0 }}>
                    <button className="btn sm" onClick={() => toggle(c)} disabled={busy || (!c.enabled && !c.models.length)}>{c.enabled ? 'ปิด' : 'เปิด'}</button>
                    <button className="btn sm" onClick={() => setEdit(c)}>แก้ไข</button>
                    <button className="btn sm danger" onClick={() => remove(c)} disabled={busy}>ลบ</button>
                  </div>
                </div>
              ))}</div>
            )}
          </div>

          <div className="card card-b stack">
            <h3 className="h">ตัวเลือกผู้ช่วย</h3>
            <label className="row" style={{ gap: 10, alignItems: 'flex-start' }}>
              <input type="checkbox" checked={opts.allow_write} onChange={e => setOpts({ ...opts, allow_write: e.target.checked })} style={{ marginTop: 3 }} />
              <span>อนุญาตให้ผู้ช่วยบันทึก/แก้ไขข้อมูล<div className="xs muted">เช่น บันทึกรับเงิน กันเงิน กลับรายการ ประมาณการรายรับ — ทำได้เฉพาะเมื่อผู้ใช้คนนั้นมีสิทธิ์ และต้องกดยืนยันทุกครั้ง · ถ้าปิด ผู้ช่วยจะดูข้อมูลได้อย่างเดียว</div></span>
            </label>
            <Field label="คำแนะนำเพิ่มเติมสำหรับผู้ช่วย (ไม่บังคับ)" hint="เช่น ศัพท์เฉพาะของสถานศึกษา รูปแบบการตอบที่ต้องการ">
              <textarea className="textarea" rows={4} value={opts.instructions} onChange={e => setOpts({ ...opts, instructions: e.target.value })} maxLength={4000} />
            </Field>
            <div><button className="btn primary" onClick={saveOpts} disabled={busy}>บันทึกตัวเลือก</button></div>
          </div>
        </div>

        <div className="stack">
          <div className="card card-b stack" style={{ gap: 10 }}>
            <h3 className="h">ขั้นตอนการตั้งค่า</h3>
            <ol className="sm" style={{ margin: 0, paddingLeft: 18, lineHeight: 1.7 }}>
              <li>เพิ่มการเชื่อมต่อ เลือกผู้ให้บริการ ใส่ API key</li>
              <li>กด "ทดสอบการเชื่อมต่อและดึงรายชื่อโมเดล"</li>
              <li>ติ๊กโมเดลที่ต้องการเปิดให้ผู้ใช้เลือก และกำหนดโมเดลเริ่มต้น</li>
              <li>เปิดหลายการเชื่อมต่อ/หลายโมเดลได้ ผู้ใช้เลือกเองในหน้าต่างสนทนา</li>
            </ol>
          </div>
          <div className="card card-b stack" style={{ gap: 10 }}>
            <h3 className="h">สิทธิ์และความปลอดภัย</h3>
            <div className="sm muted">ผู้ช่วยเรียก API เดียวกับหน้าจอปกติในนามผู้ใช้ที่กำลังสนทนา จึงเห็นและทำได้เท่าที่บทบาทของผู้ใช้คนนั้นอนุญาต ทุกการบันทึกถูกเก็บใน audit log (ai.*) · API key เก็บแบบเข้ารหัสและไม่ส่งกลับไปที่เบราว์เซอร์</div>
          </div>
          <div className="card card-b stack" style={{ gap: 10 }}>
            <h3 className="h">การพูดแทนการพิมพ์</h3>
            <div className="sm muted">ใช้ระบบแปลงเสียงเป็นข้อความของเบราว์เซอร์ (Web Speech API) ภาษาไทย รองรับ Chrome, Edge และ Safari · ต้องเปิดระบบผ่าน HTTPS หรือ localhost และอนุญาตไมโครโฟน</div>
          </div>
          <div className="card card-b stack" style={{ gap: 10 }}>
            <h3 className="h">ความเป็นส่วนตัว</h3>
            <div className="sm muted">คำถามและข้อมูลที่ผู้ช่วยค้นได้จะถูกส่งไปยังผู้ให้บริการ AI ที่ผู้ใช้เลือก หากต้องการให้ข้อมูลอยู่ภายในองค์กร ให้ใช้ LLM server ภายใน เช่น Ollama หรือ LM Studio</div>
          </div>
        </div>
      </div>
      {edit && <ConnectionEditor conn={edit} providers={P} onClose={() => setEdit(null)} onSaved={saved} />}
    </div>
  );
}

function ConnectionEditor({ conn, providers: P, onClose, onSaved }) {
  const { toast } = useApp();
  const [f, setF] = useState({ id: conn.id, name: conn.name || '', provider: conn.provider, base_url: conn.base_url || '', api_key: '', clear_key: false,
    enabled: conn.enabled !== false, models: conn.models || [], default_model: conn.default_model || '', temperature: conn.temperature ?? 0.3, sort: conn.sort || 0 });
  const [found, setFound] = useState(null);   // models listed by the server (null = not fetched yet)
  const [test, setTest] = useState(null);
  const [q, setQ] = useState('');
  const [manual, setManual] = useState('');
  const [modelTests, setModelTests] = useState({});
  const [busy, run] = useBusy();
  const set = (k, v) => setF(x => ({ ...x, [k]: v }));
  const setProvider = p => setF(x => {
    // Follow the preset URL unless the admin typed their own.
    const preset = Object.values(P).some(v => v.base_url && v.base_url === x.base_url) || !x.base_url;
    return { ...x, provider: p, base_url: preset ? P[p].base_url : x.base_url, name: x.name || P[p].label };
  });
  const urlChanged = !!conn.id && f.base_url.replace(/\/+$/, '') !== conn.base_url;
  const payload = () => ({ ...f, temperature: Number(f.temperature) });
  const toggleModel = m => setF(x => {
    const models = x.models.includes(m) ? x.models.filter(v => v !== m) : [...x.models, m];
    return { ...x, models, default_model: models.includes(x.default_model) ? x.default_model : (models[0] || '') };
  });
  const testConn = () => run(async () => {
    setTest(null);
    try {
      const r = await post('assistant/connection_test', payload());
      setFound(r.models);
      setTest({ ok: true, text: 'พบ ' + r.models.length + ' โมเดล (' + (r.ms / 1000).toFixed(1) + ' วินาที) — ติ๊กโมเดลที่จะเปิดให้ผู้ใช้เลือก' });
    } catch (e) { setFound(null); setTest({ ok: false, text: e.message }); }
  });
  const testModel = m => run(async () => {
    setModelTests(t => ({ ...t, [m]: { busy: true } }));
    try { const r = await post('assistant/model_test', { ...payload(), model: m }); setModelTests(t => ({ ...t, [m]: { ok: true, text: r.reply + ' · ' + (r.ms / 1000).toFixed(1) + ' วิ' } })); }
    catch (e) { setModelTests(t => ({ ...t, [m]: { ok: false, text: e.message } })); }
  });
  const addManual = () => { const m = manual.trim(); if (m && !f.models.includes(m)) toggleModel(m); setManual(''); };
  const save = () => run(async () => {
    try { await post('assistant/connection_save', payload()); toast('บันทึกการเชื่อมต่อแล้ว', 'ok'); onSaved(); }
    catch (e) { toast(e.message, 'err'); }
  });
  const list = (found || []).filter(m => !q || m.toLowerCase().includes(q.toLowerCase()));

  return (
    <Modal title={conn.id ? 'แก้ไขการเชื่อมต่อ API' : 'เพิ่มการเชื่อมต่อ API'} onClose={onClose} wide footer={<Fragment>
      <label className="row sm" style={{ gap: 8, marginRight: 'auto' }}>
        <input type="checkbox" checked={f.enabled} onChange={e => set('enabled', e.target.checked)} />เปิดใช้งานการเชื่อมต่อนี้
      </label>
      <button className="btn" onClick={onClose}>ยกเลิก</button>
      <button className="btn primary" onClick={save} disabled={busy}>บันทึก</button>
    </Fragment>}>
      <div className="ai-form-2">
        <Field label="ชื่อที่ผู้ใช้เห็น" required><input className="input" value={f.name} onChange={e => set('name', e.target.value)} placeholder="เช่น OpenRouter, Gemini, AI ภายในวิทยาลัย" maxLength={100} /></Field>
        <Field label="ผู้ให้บริการ">
          <select className="select" value={f.provider} onChange={e => setProvider(e.target.value)}>
            {Object.entries(P).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
          </select>
        </Field>
      </div>
      <Field label="Base URL (OpenAI-compatible)" hint="ระบบเรียก {Base URL}/models และ {Base URL}/chat/completions">
        <input className="input mono" value={f.base_url} onChange={e => set('base_url', e.target.value)} placeholder="https://…/v1" />
      </Field>
      <div className="ai-form-2">
        <Field label="API Key" hint={P[f.provider].needs_key ? 'จำเป็นสำหรับผู้ให้บริการนี้ · เก็บแบบเข้ารหัส' : 'เว้นว่างได้ถ้าเซิร์ฟเวอร์ไม่ต้องใช้คีย์'}>
          <input className="input mono" type="password" autoComplete="off" value={f.api_key} onChange={e => set('api_key', e.target.value)}
            placeholder={conn.has_key && !f.clear_key && !urlChanged ? 'บันทึกไว้แล้ว ' + conn.key_hint + ' — เว้นว่างเพื่อใช้คีย์เดิม' : 'วาง API key'} />
        </Field>
        <Field label="Temperature" hint="แนะนำ 0.2–0.4">
          <input className="input num" type="number" min="0" max="2" step="0.1" value={f.temperature} onChange={e => set('temperature', e.target.value)} />
        </Field>
      </div>
      {urlChanged && conn.has_key && !f.api_key && <div className="xs" style={{ color: 'var(--orange-fg)', marginTop: -8 }}>เปลี่ยน URL แล้ว — คีย์เดิมจะไม่ถูกส่งไปยัง URL ใหม่ กรุณาใส่คีย์อีกครั้ง</div>}
      {conn.has_key && !urlChanged && (
        <label className="row sm" style={{ gap: 8, marginTop: -6 }}><input type="checkbox" checked={f.clear_key} onChange={e => set('clear_key', e.target.checked)} />ลบคีย์ที่บันทึกไว้</label>
      )}

      <div className="ai-models-box">
        <div className="row" style={{ justifyContent: 'space-between', flexWrap: 'wrap' }}>
          <div><b style={{ color: 'var(--heading)' }}>โมเดลที่เปิดให้ผู้ใช้เลือก</b> <span className="sm muted">({f.models.length})</span></div>
          <button className="btn" onClick={testConn} disabled={busy || !f.base_url}>ทดสอบการเชื่อมต่อและดึงรายชื่อโมเดล</button>
        </div>
        {test && <Alert tone={test.ok ? 'green' : 'red'} title={test.ok ? 'เชื่อมต่อสำเร็จ' : 'เชื่อมต่อไม่สำเร็จ'}>{test.text}</Alert>}

        {f.models.length > 0 && (
          <div className="ai-picked">{f.models.map(m => {
            const t = modelTests[m];
            return (
              <div key={m} className="ai-picked-row">
                <label className="row" style={{ gap: 6, minWidth: 0, flex: 1 }} title="โมเดลเริ่มต้น">
                  <input type="radio" name="ai-default" checked={f.default_model === m} onChange={() => set('default_model', m)} />
                  <span className="mono sm" style={{ overflowWrap: 'anywhere' }}>{m}</span>
                  {f.default_model === m && <span className="xs muted">· เริ่มต้น</span>}
                </label>
                <button className="btn sm" onClick={() => testModel(m)} disabled={busy}>ทดสอบ</button>
                <button className="icon-btn plain" onClick={() => toggleModel(m)} title="นำออก" aria-label={'นำ ' + m + ' ออก'}><Icon name="x" size={14} /></button>
                {t && !t.busy && <div className={'xs ai-model-result ' + (t.ok ? 'ok' : 'err')}>{t.ok ? '✓ ' : '✗ '}{t.text}</div>}
              </div>
            );
          })}</div>
        )}

        {found && (
          <Fragment>
            <div className="search"><Icon name="search" size={16} /><input value={q} onChange={e => setQ(e.target.value)} placeholder={'ค้นหาใน ' + found.length + ' โมเดล'} /></div>
            <div className="ai-model-pick">
              {list.length === 0 && <div className="sm muted" style={{ padding: 8 }}>ไม่พบโมเดล</div>}
              {list.slice(0, 300).map(m => (
                <label key={m} className="row"><input type="checkbox" checked={f.models.includes(m)} onChange={() => toggleModel(m)} /><span className="mono sm">{m}</span></label>
              ))}
              {list.length > 300 && <div className="xs muted" style={{ padding: 6 }}>แสดง 300 จาก {list.length} — พิมพ์ค้นหาเพื่อกรอง</div>}
            </div>
          </Fragment>
        )}
        <div className="row" style={{ gap: 8 }}>
          <input className="input mono grow" value={manual} onChange={e => setManual(e.target.value)} onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); addManual(); } }}
            placeholder={'เพิ่มชื่อโมเดลเอง ' + P[f.provider].model_hint} />
          <button className="btn" onClick={addManual} disabled={!manual.trim()}>เพิ่ม</button>
        </div>
        <div className="xs muted">ควรเลือกโมเดลที่รองรับ tool / function calling เพื่อให้ผู้ช่วยค้นข้อมูลและทำงานในระบบได้ — ปุ่ม "ทดสอบ" ส่งคำถามสั้น ๆ พร้อมเครื่องมือหนึ่งตัวเพื่อตรวจ</div>
      </div>
    </Modal>
  );
}

Object.assign(window, { AssistantWidget, AssistantSettingsPage, ConnectionEditor, AiText, useSpeech });
