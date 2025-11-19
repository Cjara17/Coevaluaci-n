let mostrarNotas = false;

function loadCalificaciones() {
    fetch("ajax_calificaciones.php")
        .then(response => response.json())
        .then(data => {
            const ul = document.createElement('ul');
            ul.className = 'list-group';
            data.forEach(cal => {
                const li = document.createElement('li');
                li.className = 'list-group-item';
                li.textContent = `${cal.nombre_equipo} - Promedio: ${cal.promedio !== null ? cal.promedio.toFixed(2) : 'N/A'}`;
                ul.appendChild(li);
            });
            document.getElementById('panel-calificaciones').appendChild(ul);
        })
        .catch(error => console.error('Error loading calificaciones:', error));
}

document.addEventListener('DOMContentLoaded', function() {
    loadCalificaciones();
    document.getElementById('btn-toggle-notas').addEventListener('click', function() {
        const panel = document.getElementById('panel-calificaciones');
        panel.classList.toggle('blur-notas');
        mostrarNotas = !mostrarNotas;
        this.textContent = mostrarNotas ? 'Ocultar notas' : 'Mostrar notas';
    });
});
