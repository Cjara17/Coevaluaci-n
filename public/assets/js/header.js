document.addEventListener('DOMContentLoaded', function() {
    var courseSelect = document.querySelector('form.d-flex.me-3 select[name="id_curso"]');
    if (courseSelect) {
        courseSelect.addEventListener('change', function() {
            this.form.submit();
        });
    }
});
