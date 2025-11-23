function openDeleteModal(id, action) {
    document.getElementById('delete-id').value = id;
    document.getElementById('form-delete').action = action;
    document.getElementById('delete-action').value = 'delete';
    document.getElementById('delete-id').name = 'id_criterio'; // Para criterios
    var modal = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));
    modal.show();
}
