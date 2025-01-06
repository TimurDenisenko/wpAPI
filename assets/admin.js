jQuery(document).ready(function($){

   // Загружаем данные из data.json при загрузке сайта
   function loadDictionaryData() {
	$.getJSON("http://localhost/wpAPI/wp-content/plugins/elko-woo-import/assets/data.json", function(data) {
		const tbody = $('#category-dictionary-table tbody');
		tbody.empty(); // Очищаем таблицу перед добавлением данных

		if (data && Array.isArray(data)) {
			data.forEach(row => {
				const newRow = `
					<tr>
						<td style="border:1px solid #ccc; padding:5px;">
							<input type="text" name="elko_dictionary_alias[]" value="${row.alias || ''}" placeholder="Alias" />
						</td>
						<td style="border:1px solid #ccc; padding:5px;">
							<input type="text" name="elko_dictionary_categories[]" value="${(row.categories || []).join(', ')}" placeholder="Categories (comma-separated)" />
						</td>
						<td style="border:1px solid #ccc; padding:5px;">
							<button type="button" class="button remove-dictionary-row">Remove</button>
						</td>
					</tr>
				`;
				tbody.append(newRow);
			});
		}
	}).fail(function() {
		console.error("Не удалось загрузить данные из data.json");
	});
}

	// Добавление новой строки в таблицу
	$('#add-dictionary-row').on('click', function() {
		const newRow = `
			<tr>
				<td style="border:1px solid #ccc; padding:5px;">
					<input type="text" name="elko_dictionary_alias[]" placeholder="Alias" />
				</td>
				<td style="border:1px solid #ccc; padding:5px;">
					<input type="text" name="elko_dictionary_categories[]" placeholder="Categories (comma-separated)" />
				</td>
				<td style="border:1px solid #ccc; padding:5px;">
					<button type="button" class="button remove-dictionary-row">Remove</button>
				</td>
			</tr>
		`;
		$('#category-dictionary-table tbody').append(newRow);
	});

	// Удаление строки из таблицы
	$(document).on('click', '.remove-dictionary-row', function() {
		$(this).closest('tr').remove();
	});

	// Загружаем данные при загрузке страницы
	loadDictionaryData();



	document.getElementById("elko_save_aliases").addEventListener("click", function (event) {
		const table = document.getElementById("category-dictionary-table");
		const rows = table.rows;
		const jsonData = [];

		// Извлекаем данные из таблицы
		for (let i = 1; i < rows.length; i++) { // Начинаем с 1, чтобы пропустить заголовок
			const aliasInput = rows[i].querySelector('input[name="elko_dictionary_alias[]"]');
			const categoriesInput = rows[i].querySelector('input[name="elko_dictionary_categories[]"]');

				const alias = aliasInput.value.trim();
				const categories = categoriesInput.value.trim();

					jsonData.push({
						alias: alias,
						categories: categories.split(",").map(cat => cat.trim())})
				
		}

		// Преобразуем в JSON
		const jsonString = JSON.stringify(jsonData);

		// Отправка данных на сервер через POST
		fetch("http://localhost/wpAPI/wp-content/plugins/elko-woo-import/assets/save.php", {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
			},
			body: jsonString,
		})
	});

});