// ガチャ結果の演出
//   1. 「タップして開封」の光の玉を表示
//   2. タップすると、今回の最高レアの色で光が強くなって弾ける（UR は虹色）
//   3. 特殊演出（カットイン）が設定されたカードを引いていれば、めくる前にカットインを流す
//   4. カードを1枚ずつめくる。SSR 以上はキラキラ、UR は画面が光る
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
    const CUTIN_TIME = 2800;   // カットインを見せる時間（ミリ秒）
    const CUTIN_LEAVE = 450;   // カットインが消えていく時間（ミリ秒）
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
        if (cutin) cutin.classList.remove('show', 'leave');
    };

    // 特殊演出（カットイン）。終わったら next() を呼ぶ
    // 画像が読み込めない（ファイルが無い）ときは、カットインを飛ばしてすぐ next() へ
    const playCutin = (next) => {
        const img = cutin && cutin.querySelector('.cutin-img');
        if (!img) return next();

        let ended = false;
        const end = () => {
            if (ended) return;
            ended = true;
            cutin.classList.add('leave');
            later(() => {
                hideCutin();
                next();
            }, CUTIN_LEAVE);
        };
        const start = () => {
            cutin.classList.add('show');
            doFlash();
            cutin.addEventListener('click', end, { once: true });
            later(end, CUTIN_TIME);
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

    // 演出を飛ばして全部表示する
    const finish = () => {
        if (finished) return;
        finished = true;
        timers.forEach(clearTimeout);
        summon.classList.add('closed');
        hideCutin();
        cards.forEach((card) => flipCard(card, false));
        document.body.classList.add('reveal-done');
    };

    const open = () => {
        if (summon.classList.contains('opening')) return;
        summon.classList.add('opening');
        summon.querySelector('.summon-text').textContent =
            maxRarity >= 5 ? '……！？' : maxRarity >= 4 ? 'これは……！' : '';

        // 光が弾けたら（特殊演出があればそれを流してから）カードを順番にめくる
        const burst = maxRarity >= 5 ? 1700 : 1200;
        const flipAll = () => {
            cards.forEach((card, i) => later(() => flipCard(card, true), 250 + i * FLIP_INTERVAL));
            later(finish, 250 + cards.length * FLIP_INTERVAL + 600);
        };
        later(() => {
            summon.classList.add('closed');
            playCutin(flipAll);
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
