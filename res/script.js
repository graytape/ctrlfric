
// Inline editing
function enableEditing(row) {
    row.classList.add('editing');
    row.querySelectorAll('.display-value').forEach(el => el.classList.add('hidden'));
    row.querySelectorAll('.edit-value').forEach(el => el.classList.remove('hidden'));
    row.querySelectorAll('.edit-btn, .delete-btn').forEach(el => el.classList.add('hidden'));
    row.querySelectorAll('.save-btn, .cancel-btn').forEach(el => el.classList.remove('hidden'));
}
function disableEditing(row) {
    row.classList.remove('editing');
    row.querySelectorAll('.display-value').forEach(el => el.classList.remove('hidden'));
    row.querySelectorAll('.edit-value').forEach(el => el.classList.add('hidden'));
    row.querySelectorAll('.edit-btn, .delete-btn').forEach(el => el.classList.remove('hidden'));
    row.querySelectorAll('.save-btn, .cancel-btn').forEach(el => el.classList.add('hidden'));
}
function saveChanges(row) {
    const id            = row.dataset.id;
    const type          = row.dataset.type;
    const date          = row.querySelector('.date').value;
    const description   = row.querySelector('.description').value;
    const amount        = row.querySelector('.amount').value;
    const currency      = row.querySelector('.currency').value;
    const category      = row.querySelector('.category').value;
    const macrocategory = row.querySelector('.macrocategory').value;
    const source        = row.querySelector('.source').value;
    const note          = row.querySelector('.note').value;

    fetch('get_data.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=update&id=${id}&type=${type}&date=${date}&description=${description}&amount=${amount}&currency=${currency}&category=${category}&macrocategory=${macrocategory}&source=${source}&note=${note}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            row.querySelector('td:first-child .display-value').textContent               = formatDate(date);
            row.querySelector('td:nth-child(2) .display-value:first-child').textContent  = category;
            row.querySelector('td:nth-child(2) .display-value:nth-child(2)').textContent = macrocategory;
            row.querySelector('td:nth-child(3) .display-value:first-child').textContent  = description;
            row.querySelector('td:nth-child(3) .display-value:nth-child(3)').innerHTML   = note ? `<i class="fa fa-info-circle info-note" title="${note}""></i>` : ``;
            if (type == 'Income') {
                row.querySelector('td:nth-child(4) .display-value').textContent = `${'+'}${Number(parseFloat(amount)).toLocaleString(language, {style:"currency", currency:currency})}`;
                row.querySelector('td:nth-child(4) .display-value').setAttribute('data-currency', currency);
            } else {
                row.querySelector('td:nth-child(5) .display-value').textContent = `${'-'}${Number(parseFloat(amount)).toLocaleString(language, {style:"currency", currency:currency})}`;
                row.querySelector('td:nth-child(5) .display-value').setAttribute('data-currency', currency);
            }
            disableEditing(row);
            calculateTotalForTable();
        } else {
            alert('Failed to save changes');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to save changes');
    });
}
function deleteRow(row) {
    if (!confirm('Are you sure you want to delete this entry?')) {
        return;
    }

    const id = row.dataset.id;
    const type = row.dataset.type;

    fetch('get_data.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=delete&id=${id}&type=${type}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            row.remove();
            calculateTotalForTable();
        } else {
            alert('Failed to delete entry');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to delete entry');
    });
}


// Helper function to escape HTML
function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
// Helper function to format dates
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString();
}

// Global variables to track running totals
let runningIncomeTotal = 0;
let runningExpenseTotal = 0;

// Calculate total for transactions table
function calculateTotalForTable() {
    const tbody = document.querySelector('.transactions-table');
    if (!tbody) return;
    
    let totalIncome = 0;
    let totalExpense = 0;

    // Get all rows
    const rows = tbody.querySelectorAll('tr');
    
    rows.forEach(row => {
        // Skip rows that are hidden by the filter
        if (row.style.display === 'none') {
            return;
        }

        // Calculate incomes
        const incomeCell = row.querySelector('td.income-amount .display-value:not(.hidden)');
        if (incomeCell) {
            const incAmount = parseFloat(incomeCell.textContent.replace(/[^0-9,-]+/g, '').replace(/[,]+/g, '.'));
            const currency = incomeCell.dataset.currency;
            if (!isNaN(incAmount)) {
                totalIncome += (incAmount * exchange_rates[main_currency][currency]);
            }
        }

        // Calculate expenses
        const expenseCell = row.querySelector('td.expense-amount .display-value:not(.hidden)');
        if (expenseCell) {
            const expAmount = parseFloat(expenseCell.textContent.replace(/[^0-9,-]+/g, '').replace(/[,]+/g, '.'));
            const currency = expenseCell.dataset.currency;
            if (!isNaN(expAmount)) {
                totalExpense += (expAmount * exchange_rates[main_currency][currency]);
            }
        }
    });

    // Update total divs
    const totalIncomesDiv = document.getElementById('total-incomes');
    const totalExpensesDiv = document.getElementById('total-expenses');
    
    if (totalIncomesDiv) {
        totalIncomesDiv.textContent = `${totalIncome.toLocaleString(language, {style:"currency", currency:main_currency})}`;
        totalIncomesDiv.classList.add('positive');
    }
    if (totalExpensesDiv) {
        totalExpensesDiv.textContent = `${totalExpense.toLocaleString(language, {style:"currency", currency:main_currency})}`;
        totalExpensesDiv.classList.add('negative');
    }
}


const limit = 10;
function loadMoreTransactions() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    const button = document.getElementById('load-more-entries');
    
    button.disabled = true;
    button.textContent = t["Loading..."];

    const tbody = document.querySelector('.transactions-table');
    const currentOffset = parseInt(tbody.dataset.offset) || 0;

    fetch(`get_data.php?offset=${currentOffset}&limit=10&start_date=${startDate}&end_date=${endDate}`)
        .then(response => response.json())
        .then(data => {
            data.transactions.forEach(transaction => {
                const row = document.createElement('tr');
                row.dataset.id = transaction.id;
                row.dataset.type = transaction.type;
                transaction.amount_val = transaction.amount;
                transaction.amount = Number(transaction.amount).toLocaleString();
                const isExpense = transaction.type === 'Expense';

                var selectCurrency = `<select class="edit-value hidden currency">`;
                for (const key in currencies) {
                    if (currencies.hasOwnProperty(key)) {
                        const currency = currencies[key];
                        selectCurrency += `<option value="`+currency.code+`" `+(transaction.currency==currency.code?`selected`:``)+`>`+currency.code+`</option>`;
                    }
                }
                selectCurrency += `</select>`;

                
                const amountDisplay = isExpense
                    ? `<td></td><td class="expense-amount"><span class="display-value negative" data-currency="${transaction.currency}">-${transaction.amount}</span>
                       <input type="number" step="0.01" class="edit-value hidden amount" value="${transaction.amount_val}">${selectCurrency}</td>`

                    : `<td class="income-amount"><span class="display-value positive" data-currency="${transaction.currency}">+${transaction.amount}</span>
                       <input type="number" step="0.01" class="edit-value hidden amount" value="${transaction.amount_val}">${selectCurrency}</td><td></td>`;

                row.innerHTML = `
                    <td>
                        <span class="display-value">${formatDate(transaction.date)}</span>
                        <input type="date" class="edit-value hidden date" value="${transaction.date}">
                       <input type="text" class="edit-value hidden source" value="${escapeHtml(transaction.source || '')}" placeholder="source">
                    </td>
                    <td>
                        <span class="display-value tag-category">${transaction.category}</span>
                        <span class="display-value tag-macrocategory">${transaction.macrocategory}</span>
                        <select class="edit-value hidden category" value="${escapeHtml(transaction.category)}">
                            ${categories.map(cat => `
                                <option value="${escapeHtml(cat)}" ${cat === transaction.category ? 'selected' : ''}>
                                    ${escapeHtml(cat)}
                                </option>
                            `).join('')}
                        </select>
                        <select class="edit-value hidden macrocategory" value="${escapeHtml(transaction.macrocategory)}">
                            ${macrocategories.map(macrocat => `
                                <option value="${escapeHtml(macrocat)}" ${macrocat === transaction.macrocategory ? 'selected' : ''}>
                                    ${escapeHtml(macrocat)}
                                </option>
                            `).join('')}
                        </select>
                    </td>
                    <td>
                        <span class="display-value">${escapeHtml(transaction.description)}</span>
                        <input type="text" class="edit-value hidden description" value="${escapeHtml(transaction.description)}" placeholder="description">
                        <span class="display-value">` + (transaction.note?`<i class="fa fa-info-circle info-note" title="${transaction.note}"></i>`:``) + `</span>
                        <input type="text" class="edit-value hidden note" value="${escapeHtml(transaction.note || '')}" placeholder="note">
                    </td>
                    ${amountDisplay}
                    <td class="actions">
                        <button class="edit-btn" title="Edit"><i class="fa fa-edit"></i></button>
                        <button class="delete-btn" title="Delete"><i class="fa fa-trash"></i></button>
                        <button class="save-btn hidden" title="Save"><i class="fa fa-check"></i></button>
                        <button class="cancel-btn hidden" title="Cancel"><i class="fa fa-times"></i></button>
                    </td>
                `;
                tbody.appendChild(row);
            });

            button.disabled = false;
            button.textContent = t["Load More"];
            
            if (!data.hasMore) {
                button.style.display = 'none';
            }
            
            tbody.dataset.offset = currentOffset + data.transactions.length;
            calculateTotalForTable(); 
        })
        .catch(error => {
            console.error('Error:', error);
            button.disabled = false;
            button.textContent = t["Load More"];
        });
}

// Attiva le funzioni al caricamento della pagina
document.addEventListener('DOMContentLoaded', () => {
    calculateTotalForTable();
});

// Event Delegation for dynamic elements
document.addEventListener('click', function(e) {
    const target = e.target;
    const row = target.closest('tr');
    
    if (!row) return;

    if (target.closest('.edit-btn')) {
        enableEditing(row);
    } else if (target.closest('.save-btn')) {
        saveChanges(row);
    } else if (target.closest('.cancel-btn')) {
        disableEditing(row);
    } else if (target.closest('.delete-btn')) {
        deleteRow(row);
    }
});

// Add event listeners
document.addEventListener('DOMContentLoaded', () => {
    calculateTotalForTable();
    
    const loadMoreButton = document.getElementById('load-more-entries');
    if (loadMoreButton) {
        loadMoreButton.addEventListener('click', loadMoreTransactions);
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const searchInput  = document.getElementById('searchInput');
    const searchButton = document.getElementById('searchButton');
    const tbody        = document.querySelector('.transactions-table.tbody');
    const startDate    = document.getElementById('start_date');
    const endDate      = document.getElementById('end_date');

    // Client-side filtering
    searchInput.addEventListener('input', function(e) {
        const searchText = e.target.value.toLowerCase();
        
        // Only filter if we have 3 or more characters
        if (searchText.length >= 3) {
            const rows = tbody.getElementsByTagName('tr');
            
            Array.from(rows).forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchText) ? '' : 'none';
            });
        } else {
            // Show all rows if search text is less than 3 characters
            const rows = tbody.getElementsByTagName('tr');
            Array.from(rows).forEach(row => {
                row.style.display = '';
            });
        }
        calculateTotalForTable();
    });

    // Server-side search
    searchButton.addEventListener('click', function() {
        const searchText = searchInput.value;
        if (searchText.length < 3) {
            alert('Please enter at least 3 characters to search');
            return;
        }

        fetch(`get_data.php?action=search&query=${encodeURIComponent(searchText)}&start_date=${startDate.value}&end_date=${endDate.value}`)
            .then(response => response.json())
            .then(data => {
                // Clear existing rows
                tbody.innerHTML = '';
                
                // Add new rows
                data.transactions.forEach(entry => {
                    const row = createTransactionRow(entry);
                    tbody.appendChild(row);
                });
                calculateTotalForTable();
            })
            .catch(error => {
                console.error('Error:', error);
            });
    });

    // Helper function to create a transaction row
    function createTransactionRow(entry) {
        const row = document.createElement('tr');
        row.dataset.id = entry.id;
        row.dataset.type = entry.type;

        entry.amount_val = entry.amount;
        entry.amount = Number(entry.amount).toLocaleString();


        var selectCurrency = `<select class="edit-value hidden currency">`;
        for (const key in currencies) {
            if (currencies.hasOwnProperty(key)) {
                const currency = currencies[key];
                selectCurrency += `<option value="`+currency.code+`" `+(entry.currency==currency.code?`selected`:``)+`>`+currency.code+`</option>`;
            }
        }
        selectCurrency += `</select>`;

        row.innerHTML = `
            <td>
                <span class="display-value">${formatDate(entry.date)}</span>
                <input type="date" class="edit-value hidden date" value="${entry.date}">
                <input type="text" class="edit-value hidden source" value="${entry.source || ''}" placeholder="source">
            </td>
            <td>
                <span class="display-value tag-category">${entry.category}</span>
                <span class="display-value tag-macrocategory">${entry.macrocategory}</span>
                <select class="edit-value hidden category">
                    ${categories.map(cat => `<option value="${cat}" ${cat === entry.category ? 'selected' : ''}>${cat}</option>`).join('')}
                </select>
                <select class="edit-value hidden macrocategory">
                    ${macrocategories.map(macrocat => `<option value="${macrocat}" ${macrocat === entry.macrocategory ? 'selected' : ''}>${macrocat}</option>`).join('')}
                </select>
            </td>
            <td>
                <span class="display-value">${entry.description}</span>
                <input type="text" class="edit-value hidden description" value="${entry.description}" placeholder="description">
                <span class="display-value">` + (entry.note?`<i class="fa fa-info-circle info-note" title="${entry.note}"></i>`:``) + `</span>
                <input type="text" class="edit-value hidden note" value="${entry.note || ''}" placeholder="note">
            </td>
            ${entry.type === 'Expense' ? 
                `<td></td><td class="expense-amount">
                    <span class="display-value negative" data-currency="${entry.currency}">-${entry.amount}</span>
                    <input type="number" step="0.01" class="edit-value hidden amount" value="${entry.amount_val}">
                    ${selectCurrency}
                </td>` : 
                `<td class="income-amount">
                    <span class="display-value positive" data-currency="${entry.currency}">+${entry.amount}</span>
                    <input type="number" step="0.01" class="edit-value hidden amount" value="${entry.amount_val}">
                    ${selectCurrency}
                </td><td></td>`
            }
            <td class="actions">
                <button class="edit-btn" title="Edit"><i class="fa fa-edit"></i></button>
                <button class="delete-btn" title="Delete"><i class="fa fa-trash"></i></button>
                <button class="save-btn hidden" title="Save"><i class="fa fa-check"></i></button>
                <button class="cancel-btn hidden" title="Cancel"><i class="fa fa-times"></i></button>
            </td>
        `;
        
        return row;
    }

    // Helper function to format date
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString();
    }
});