// ガチャ結果の演出
//   1. 「タップして開封」の光の玉を表示
//   2. タップすると、今回の最高レアの色で光が強くなって弾ける（UR は虹色）
//   3. カードを1枚ずつめくる。SSR 以上はキラキラ、UR は画面が光る
//   4. 特殊演出（カットイン）が設定されたカードの番が来たら、そのカードがめくれる直前に
//      「カードが震えて光る（ため）→ 足元 → フラッシュ → 顔 → フラッシュ → 立ち絵全体」を流す
// JavaScript が無い／動きを減らす設定のときは html に js クラスが付かないので、結果はそのまま表示される
(() => {
    const grid = document.querySelector('.card-grid');
    if (!grid || !document.documentElement.classList.contains('js')) return;

    const cards = [...grid.querySelectorAll('.card')];
    const summon = document.querySelector('.summon');
    const flash = document.querySelector('.flash');
    const cutin = document.querySelector('.cutin');
    const maxRarity = Number(grid.dataset.maxRarity || 1);

    const FLIP_INTERVAL = 220; // カードをめくる間隔（ミリ秒）
    const CHARGE_TIME = 900;   // カットイン前に、カードが震えて光る「ため」の時間
    // カットインの段階と、それぞれを見せる時間（ミリ秒）
    const CUTIN_STAGES = [
        ['feet', 1300], // 足元を大きく
        ['face', 1300], // 顔を大きく
        ['full', 2800], // 立ち絵全体と名前
    ];
    const CUTIN_LEAVE = 450;   // カットインが消えていく時間
    const AFTER_CUTIN = 700;   // カットインのカードがめくれた後、次のカードまで少し間をあける
    const cutinIndex = cutin ? Number(cutin.dataset.index) : -1;
    const timers = [];
    let finished = false;

    const later = (fn, ms) => timers.push(setTimeout(fn, ms));
    const overlayOpen = () => !summon.classList.contains('closed');

    const sparkle = (card) => {
        for (let i = 0; i < 10; i++) {
            const s = document.createElement('span');
            s.className = 'sparkle';
            const angle = (Math.PI * 2 * i) / 10;
            const dist = 60 + Math.random() * 30;
            s.style.setProperty('--dx', `${Math.cos(angle) * dist}px`);
            s.style.setProperty('--dy', `${Math.sin(angle) * dist}px`);
            card.querySelector('.card-flip').appendChild(s);
            setTimeout(() => s.remove(), 900);
        }
    };

    const flipCard = (card, withEffects) => {
        if (card.classList.contains('revealed')) return;
        card.classList.add('revealed');
        if (!withEffects) return;
        const rarity = Number(card.dataset.rarity);
        if (rarity >= 4) sparkle(card);
        if (rarity >= 5) doFlash();
    };

    const doFlash = () => {
        flash.classList.remove('on');
        void flash.offsetWidth; // アニメーションを最初からやり直すため
        flash.classList.add('on');
    };

    const hideCutin = () => {
        if (!cutin) return;
        cutin.classList.remove('show', 'leave');
        CUTIN_STAGES.forEach(([n]) => cutin.classList.remove(`stage-${n}`));
    };

    // 特殊演出（カットイン）。終わったら next() を呼ぶ
    // 画像が読み込めない（ファイルが無い）ときは、カットインを飛ばしてすぐ next() へ
    const playCutin = (next) => {
        const img = cutin && cutin.querySelector('.cutin-img');
        if (!img) return next();

        let stage = -1;
        let stageTimer = null;
        let ended = false;

        const end = () => {
            if (ended) return;
            ended = true;
            clearTimeout(stageTimer);
            cutin.classList.add('leave');
            later(() => {
                hideCutin();
                next();
            }, CUTIN_LEAVE);
        };
        // 次の段階へ（段階が変わるたびにフラッシュ）
        const nextStage = () => {
            clearTimeout(stageTimer);
            stage++;
            if (stage >= CUTIN_STAGES.length) return end();
            const [name, time] = CUTIN_STAGES[stage];
            CUTIN_STAGES.forEach(([n]) => cutin.classList.remove(`stage-${n}`));
            cutin.classList.add(`stage-${name}`);
            doFlash();
            stageTimer = setTimeout(nextStage, time);
            timers.push(stageTimer);
        };
        const start = () => {
            cutin.classList.add('show');
            // タップすると次の段階へ進む
            cutin.addEventListener('click', () => { if (!ended) nextStage(); });
            nextStage();
        };

        if (img.complete) {
            return img.naturalWidth > 0 ? start() : next();
        }
        let waited = false;
        img.addEventListener('load', () => { if (!waited) { waited = true; start(); } }, { once: true });
        img.addEventListener('error', () => { if (!waited) { waited = true; next(); } }, { once: true });
        // 読み込みが遅すぎるときも待たずに進む
        later(() => { if (!waited) { waited = true; next(); } }, 1500);
    };

    // カードを前から1枚ずつめくる。カットインのカードの番が来たら、ため → カットイン → めくる
    const flipFrom = (i) => {
        if (finished) return;
        if (i >= cards.length) {
            later(finish, 600);
            return;
        }
        const card = cards[i];
        if (i === cutinIndex) {
            card.classList.add('charging');
            later(() => {
                playCutin(() => {
                    card.classList.remove('charging');
                    flipCard(card, true);
                    later(() => flipFrom(i + 1), AFTER_CUTIN);
                });
            }, CHARGE_TIME);
            return;
        }
        flipCard(card, true);
        later(() => flipFrom(i + 1), FLIP_INTERVAL);
    };

    // 演出を飛ばして全部表示する
    const finish = () => {
        if (finished) return;
        finished = true;
        timers.forEach(clearTimeout);
        summon.classList.add('closed');
        hideCutin();
        cards.forEach((card) => {
            card.classList.remove('charging');
            flipCard(card, false);
        });
        document.body.classList.add('reveal-done');
    };

    const open = () => {
        if (summon.classList.contains('opening')) return;
        summon.classList.add('opening');
        summon.querySelector('.summon-text').textContent =
            maxRarity >= 5 ? '……！？' : maxRarity >= 4 ? 'これは……！' : '';

        // 光が弾けたら、カードを順番にめくる
        const burst = maxRarity >= 5 ? 1700 : 1200;
        later(() => {
            summon.classList.add('closed');
            later(() => flipFrom(0), 250);
        }, burst);
    };

    summon.querySelector('.summon-stage').addEventListener('click', open);
    summon.querySelector('.summon-skip').addEventListener('click', (e) => {
        e.stopPropagation();
        finish();
    });
    // めくっている途中でカードをクリックしたら、残りを一気にめくる
    grid.addEventListener('click', () => {
        if (!finished && !overlayOpen() && !(cutin && cutin.classList.contains('show'))) finish();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') finish();
        if ((e.key === 'Enter' || e.key === ' ') && overlayOpen()) {
            e.preventDefault();
            open();
        }
    });
})();
