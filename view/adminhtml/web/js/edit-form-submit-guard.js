define([], () => () => {
    const form = document.getElementById('edit_form');

    if (form === null) {
        return;
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        event.stopImmediatePropagation();
    }, true);
});
