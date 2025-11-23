document.addEventListener('DOMContentLoaded', function() {
    var emailInput = document.getElementById('email');
    var passwordField = document.getElementById('password-field');
    if (emailInput) {
        emailInput.addEventListener('input', function() {
            var email = this.value;
            if (email.length > 0 && !email.endsWith('@alu.uct.cl')) {
                passwordField.style.display = 'block';
                document.getElementById('password').required = true;
            } else {
                passwordField.style.display = 'none';
                document.getElementById('password').required = false;
            }
        });
    }
});
