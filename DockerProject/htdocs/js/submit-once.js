// ガチャのボタンの二度押し防止
// 連打すると同じガチャが2回実行されてしまうので、ページ内で最初の1回だけ送信する。
// （ボタンを disabled にすると、押したボタンの name/value が送られなくなるので、送信イベントで止める）
(() => {
    let submitted = false;
    document.addEventListener('submit', (e) => {
        if (submitted) {
            e.preventDefault();
            return;
        }
        submitted = true;
        e.target.classList.add('submitting');
    });
    // ブラウザの「戻る」でこのページに戻ってきたときは、また押せるようにする
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
            submitted = false;
            document.querySelectorAll('form.submitting').forEach((f) => f.classList.remove('submitting'));
        }
    });
})();
