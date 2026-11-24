// バトルの演出（サーバで計算済みのバトルログを再生する）
//
// ログ（#battle-data の JSON）:
//   units:  登場するユニット（味方・敵。敵は wave 番号つき）
//   events: { t: 'wave' | 'turn' | 'action' | 'expire' | 'end', ... } の配列
// 勝敗・報酬はサーバで決まっているので、ここでは見せ方だけを担当する
(function () {
    'use strict';

    const dataEl = document.getElementById('battle-data');
    if (!dataEl) {
        return;
    }
    const log = JSON.parse(dataEl.textContent);

    const el = {
        allies: document.getElementById('battle-allies'),
        enemies: document.getElementById('battle-enemies'),
        banner: document.getElementById('battle-banner'),
        wave: document.getElementById('battle-wave'),
        turn: document.getElementById('battle-turn'),
        log: document.getElementById('battle-log'),
        result: document.getElementById('battle-result'),
        skip: document.getElementById('battle-skip'),
    };

    const ATTR = { ether: 'エーテル', fire: '炎', ice: '氷', physical: '物理', electric: '電気' };
    const ROLE = { attacker: 'アタッカー', tank: 'タンク', healer: 'ヒーラー', support: 'サポート' };
    const STATUS = {
        atk_up: { short: '攻↑', name: '攻撃アップ' },
        def_down: { short: '防↓', name: '防御ダウン' },
        taunt: { short: '挑発', name: '挑発' },
    };
    const waves = Math.max(1, ...log.units.filter(u => u.wave).map(u => u.wave));

    // 再生中の状態
    const units = {};      // uid => { data, hp, node, status: {} }
    let speed = 1;
    let skipping = false;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const sleep = ms => (skipping ? Promise.resolve() : new Promise(r => setTimeout(r, ms / speed)));

    // ---- ユニットの表示 ----

    function createUnitNode(u) {
        const node = document.createElement('div');
        node.className = `unit unit-${u.side}`;
        node.dataset.uid = u.uid;

        const img = document.createElement('img');
        img.src = u.image;
        img.alt = '';
        node.appendChild(img);

        const name = document.createElement('div');
        name.className = 'unit-name';
        name.textContent = u.name + (u.level ? ` Lv${u.level}` : '');
        node.appendChild(name);

        const tags = document.createElement('div');
        tags.className = 'unit-tags';
        if (u.attribute) {
            tags.appendChild(tag(`attr-badge attr-${u.attribute}`, ATTR[u.attribute]));
        }
        if (u.role) {
            tags.appendChild(tag('role-badge', ROLE[u.role]));
        }
        if (u.weakness) {
            tags.appendChild(tag(`attr-badge attr-${u.weakness}`, `弱:${ATTR[u.weakness]}`));
        }
        if (u.resistance) {
            tags.appendChild(tag('attr-badge resist', `耐:${ATTR[u.resistance]}`));
        }
        node.appendChild(tags);

        const bar = document.createElement('div');
        bar.className = 'hp-bar';
        bar.innerHTML = '<span></span>';
        node.appendChild(bar);

        const hp = document.createElement('div');
        hp.className = 'hp-text';
        node.appendChild(hp);

        const status = document.createElement('div');
        status.className = 'unit-status';
        node.appendChild(status);

        return node;
    }

    function tag(className, text) {
        const s = document.createElement('span');
        s.className = className;
        s.textContent = text;
        return s;
    }

    function render(uid) {
        const u = units[uid];
        const ratio = Math.max(0, u.hp / u.data.max_hp);
        const bar = u.node.querySelector('.hp-bar span');
        bar.style.width = `${ratio * 100}%`;
        bar.className = ratio < 0.25 ? 'low' : ratio < 0.5 ? 'mid' : '';
        u.node.querySelector('.hp-text').textContent = `${u.hp} / ${u.data.max_hp}`;
        u.node.classList.toggle('down', u.hp <= 0);

        const st = u.node.querySelector('.unit-status');
        st.textContent = '';
        for (const [type, s] of Object.entries(u.status)) {
            st.appendChild(tag(`status status-${type}`, `${STATUS[type].short}${s.turns}`));
        }
    }

    function showWave(n) {
        el.enemies.textContent = '';
        for (const u of log.units.filter(u => u.side === 'enemy' && (u.wave ?? 1) === n)) {
            el.enemies.appendChild(units[u.uid].node);
            render(u.uid);
        }
        el.wave.textContent = waves > 1 ? `WAVE ${n} / ${waves}` : '';
    }

    // ---- ログ（テキスト） ----

    function addLog(text, className = '') {
        const li = document.createElement('li');
        li.textContent = text;
        if (className) {
            li.className = className;
        }
        el.log.appendChild(li);
        // ログの枠の中だけをスクロールする（ページ全体はスクロールさせず、バトル画面を見たままにする）
        el.log.scrollTop = el.log.scrollHeight;
    }

    const nameOf = uid => units[uid].data.name;

    function describeResult(actorUid, r) {
        const target = nameOf(r.target);
        switch (r.type) {
            case 'damage': {
                const eff = r.eff === 'weak' ? '（弱点！）' : r.eff === 'resist' ? '（いまひとつ）' : '';
                const ko = r.ko ? (units[r.target].data.side === 'ally' ? ` ${target} は倒れた…` : ` ${target} を倒した！`) : '';
                return `${target} に ${r.amount} ダメージ${eff}${ko}`;
            }
            case 'heal':
                return `${target} の HP が ${r.amount} 回復`;
            case 'status':
                if (r.status === 'taunt') {
                    return `${target} は敵の攻撃を引きつけている（受けるダメージ -${r.value}%・${r.turns}ターン）`;
                }
                return `${target} の${STATUS[r.status].name} ${r.value}%（${r.turns}ターン）`;
        }
        return '';
    }

    // ---- 演出 ----

    function popup(uid, text, className) {
        if (skipping) {
            return;
        }
        const p = document.createElement('span');
        p.className = `popup ${className}`;
        p.textContent = text;
        units[uid].node.appendChild(p);
        setTimeout(() => p.remove(), 1200 / speed);
    }

    async function playAction(ev) {
        const actor = units[ev.actor];
        const isSkill = ev.kind === 'skill';
        addLog(isSkill ? `${actor.data.name} の『${ev.skill}』！` : `${actor.data.name} の攻撃！`, isSkill ? 'log-skill' : '');

        if (!skipping) {
            if (isSkill) {
                el.banner.textContent = `${actor.data.name}『${ev.skill}』`;
                el.banner.className = `battle-banner show side-${actor.data.side}`;
                await sleep(500);
            }
            if (!reducedMotion) {
                actor.node.classList.add('acting');
            }
            await sleep(250);
        }

        for (const r of ev.results) {
            const t = units[r.target];
            if (r.type === 'damage') {
                t.hp = r.hp;
                if (r.ko) {
                    t.status = {};
                }
                popup(r.target, `-${r.amount}${r.eff === 'weak' ? ' WEAK!' : r.eff === 'resist' ? ' RESIST' : ''}`, `damage eff-${r.eff ?? 'none'}`);
                if (!skipping && !reducedMotion) {
                    t.node.classList.remove('hit');
                    void t.node.offsetWidth; // アニメーションをやり直す
                    t.node.classList.add('hit');
                }
            } else if (r.type === 'heal') {
                t.hp = r.hp;
                popup(r.target, `+${r.amount}`, 'heal');
            } else if (r.type === 'status') {
                t.status[r.status] = { value: r.value, turns: r.turns };
                popup(r.target, STATUS[r.status].short, 'buff');
            }
            render(r.target);
            addLog(`　${describeResult(ev.actor, r)}`, r.ko ? 'log-ko' : '');
        }

        await sleep(450);
        actor.node.classList.remove('acting');
        el.banner.className = 'battle-banner';
    }

    function tickStatuses() {
        // サーバと同じく、ターンの終わりに残りターンを1つ減らす（表示用）
        for (const u of Object.values(units)) {
            for (const type of Object.keys(u.status)) {
                u.status[type].turns--;
            }
        }
    }

    async function play() {
        for (const ev of log.events) {
            switch (ev.t) {
                case 'wave':
                    if (ev.wave > 1) {
                        addLog(`— WAVE ${ev.wave} —`, 'log-wave');
                        if (!skipping) {
                            el.banner.textContent = `WAVE ${ev.wave}`;
                            el.banner.className = 'battle-banner show side-wave';
                            await sleep(600);
                            el.banner.className = 'battle-banner';
                        }
                    }
                    showWave(ev.wave);
                    break;
                case 'turn':
                    if (ev.turn > 1) {
                        tickStatuses();
                        Object.keys(units).forEach(render);
                    }
                    el.turn.textContent = `ターン ${ev.turn}`;
                    addLog(`ターン ${ev.turn}`, 'log-turn');
                    break;
                case 'action':
                    await playAction(ev);
                    break;
                case 'expire':
                    delete units[ev.target].status[ev.status];
                    render(ev.target);
                    addLog(`　${nameOf(ev.target)} の${STATUS[ev.status].name}が切れた`, 'log-muted');
                    break;
                case 'end':
                    finish(ev.result);
                    break;
            }
        }
    }

    function finish(result) {
        const label = { win: '勝利！', lose: '敗北…', draw: '引き分け' }[result];
        addLog(label, `log-end result-${result}`);
        el.banner.textContent = label;
        el.banner.className = `battle-banner show end result-${result}`;
        el.result.classList.add('show');
        el.skip.disabled = true;
        document.querySelectorAll('.battle-controls .speed').forEach(b => (b.disabled = true));
    }

    // ---- 初期化 ----

    for (const u of log.units) {
        units[u.uid] = { data: u, hp: u.hp, node: createUnitNode(u), status: {} };
    }
    for (const u of log.units.filter(u => u.side === 'ally')) {
        el.allies.appendChild(units[u.uid].node);
        render(u.uid);
    }

    document.querySelectorAll('.battle-controls .speed').forEach(btn => {
        btn.addEventListener('click', () => {
            speed = Number(btn.dataset.speed);
            document.querySelectorAll('.battle-controls .speed').forEach(b => b.classList.toggle('active', b === btn));
        });
    });
    el.skip.addEventListener('click', () => {
        skipping = true;
    });

    // 「視差効果を減らす」設定の人には演出をせず、すぐに結果を出す
    if (reducedMotion) {
        skipping = true;
    }
    play();
})();
