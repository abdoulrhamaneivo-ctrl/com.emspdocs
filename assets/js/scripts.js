document.addEventListener('DOMContentLoaded', () => {
    const alertNode = document.querySelector('.auto-dismiss');
    if (alertNode) {
        window.setTimeout(() => {
            alertNode.classList.add('fade');
            window.setTimeout(() => alertNode.remove(), 250);
        }, 4500);
    }
});



