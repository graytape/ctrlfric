function convertEntryAmount(amount, currency, rate, rateCurrency, target, rates) {
    if (Number(rate) > 0 && rates[target][rateCurrency] !== undefined) return amount * Number(rate) * rates[target][rateCurrency];
    return amount * (rates[target][currency] ?? 1);
}

// Inline editing
function enableEditing(row) {
    row.classList.add('editing');
    if (!row.querySelector('.specific-rate')) {
        const cell = row.querySelector('.amount').parentElement;
        const wrapper = document.createElement('div'); wrapper.className = 'edit-value entry-rate-edit';
        const input = document.createElement('input');
        Object.assign(input, {type:'number',step:'any',min:'0.0000000001',max:'9999999999',className:'specific-rate',value:row.dataset.exchangeRate || '',placeholder:t['Optional'] || 'Optional'});
        input.setAttribute('aria-label',t['Exchange rate'] || 'Exchange rate');
        const target = document.createElement('select'); target.className = 'rate-currency';
        Object.keys(exchange_rates).forEach(code => {const option = new Option(code,code); option.selected = code === (row.dataset.rateCurrency || main_currency); target.add(option);});
        const hint = document.createElement('small');
        const update = () => {hint.textContent = `1 ${row.querySelector('.currency').value} = ${input.value || (exchange_rates[target.value][row.querySelector('.currency').value])} ${target.value}`;};
        wrapper.append(input,target,hint); cell.append(wrapper);
        input.addEventListener('input',update); target.addEventListener('change',update); row.querySelector('.currency').addEventListener('change',update); update();
    }

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
    const exchange_rate = row.querySelector('.specific-rate').value;
    const rate_currency = row.querySelector('.rate-currency').value;

    fetch('get_data.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({action: 'update', id, type, date, description, amount, currency, category, macrocategory, source, note, exchange_rate, rate_currency})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(t['Failed to save changes']);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert(t['Failed to save changes']);
    });
}
function deleteRow(row) {
    if (!confirm(t['Are you sure you want to delete this entry?'])) {
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
            const tbody = row.closest('tbody');
            row.remove();
            tbody.dataset.offset = Math.max(0, (Number(tbody.dataset.offset) || 0) - 1);
            calculateTotalForTable();
        } else {
            alert(t['Failed to delete entry']);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert(t['Failed to delete entry']);
    });
}


// Helper function to escape HTML
function escapeHtml(unsafe) {
    return String(unsafe ?? '')
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
// Helper function to format dates
function formatDate(dateString) {
    const date = new Date(dateString + 'T12:00:00');
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
            const incAmount = Math.abs(Number(row.dataset.amount));
            const currency = incomeCell.dataset.currency;
            if (!isNaN(incAmount)) {
                totalIncome += (convertEntryAmount(incAmount, currency, row.dataset.exchangeRate, row.dataset.rateCurrency, main_currency, exchange_rates));
            }
        }

        // Calculate expenses
        const expenseCell = row.querySelector('td.expense-amount .display-value:not(.hidden)');
        if (expenseCell) {
            const expAmount = Math.abs(Number(row.dataset.amount));
            const currency = expenseCell.dataset.currency;
            if (!isNaN(expAmount)) {
                totalExpense += (convertEntryAmount(expAmount, currency, row.dataset.exchangeRate, row.dataset.rateCurrency, main_currency, exchange_rates));
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
async function loadMoreTransactions() {
    const button=document.getElementById('load-more-entries'),tbody=document.querySelector('.transactions-table');
    button.disabled=true;button.textContent=t['Loading...'];
    try {
        const params=new URLSearchParams(entryFilters);params.set('offset',tbody.dataset.offset||'0');params.set('limit','10');
        const response=await fetch('get_data.php?'+params);const data=await response.json();if(!response.ok)throw Error(data.error);
        data.transactions.forEach(entry=>tbody.appendChild(window.createTransactionRow(entry)));
        tbody.dataset.offset=Number(tbody.dataset.offset||0)+data.transactions.length;
        button.hidden=!data.hasMore;calculateTotalForTable();
    } catch(e){alert(e.message||t['Error']);}
    finally{button.disabled=false;button.textContent=t['Load More'];}
}

// Initialize functions when the page loads.
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

document.addEventListener('DOMContentLoaded',()=>{
    calculateTotalForTable();
    document.getElementById('load-more-entries')?.addEventListener('click',loadMoreTransactions);
    // Helper function to create a transaction row
    function createTransactionRow(entry) {
        const row = document.createElement('tr');
        row.dataset.id = entry.id;
        row.dataset.amount = entry.amount;
        row.dataset.exchangeRate = entry.exchange_rate || '';
        row.dataset.rateCurrency = entry.rate_currency || '';
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
                <input type="text" class="edit-value hidden source" value="${escapeHtml(entry.source || '')}" placeholder="${escapeHtml(t['Source'])}">
            </td>
            <td>
                <span class="display-value tag-category">${escapeHtml(entry.category)}</span>
                <span class="display-value tag-macrocategory">${escapeHtml(entry.macrocategory)}</span>
                <select class="edit-value hidden category">
                    ${categories.map(cat => `<option value="${escapeHtml(cat)}" ${cat === entry.category ? 'selected' : ''}>${escapeHtml(cat)}</option>`).join('')}
                </select>
                <select class="edit-value hidden macrocategory">
                    ${macrocategories.map(macrocat => `<option value="${escapeHtml(macrocat)}" ${macrocat === entry.macrocategory ? 'selected' : ''}>${escapeHtml(macrocat)}</option>`).join('')}
                </select>
            </td>
            <td>
                <span class="display-value">${escapeHtml(entry.description)}</span>
                <input type="text" class="edit-value hidden description" value="${escapeHtml(entry.description)}" placeholder="${escapeHtml(t['Description'])}">
                <span class="display-value">` + (entry.note?`<i class="fa-solid fa-circle-info info-note" title="${escapeHtml(entry.note)}"></i>`:``) + `</span>
                <input type="text" class="edit-value hidden note" value="${escapeHtml(entry.note || '')}" placeholder="${escapeHtml(t['Note'])}">
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
                <button class="edit-btn" title="${escapeHtml(t['Edit'])}"><i class="fa-solid fa-pen-to-square"></i></button>
                <button class="delete-btn" title="${escapeHtml(t['Delete'])}"><i class="fa-solid fa-trash"></i></button>
                <button class="save-btn hidden" title="${escapeHtml(t['Save'])}"><i class="fa-solid fa-check"></i></button>
                <button class="cancel-btn hidden" title="${escapeHtml(t['Cancel'])}"><i class="fa-solid fa-xmark"></i></button>
            </td>
        `;
        
        return row;
    }

    window.createTransactionRow=createTransactionRow;

    // Helper function to format date
    function formatDate(dateString) {
        const date = new Date(dateString + 'T12:00:00');
        return date.toLocaleDateString();
    }
});
// Native links make category navigation work with touch and keyboard alike.
if (document.body.dataset.categoryFilter === '1') {
    const enhanceTags = () => document.querySelectorAll('.transactions-table .tag-category').forEach(tag => {
        const params = new URLSearchParams({category: tag.textContent, all_dates: '1'});
        if (tag.tagName === 'A') {
            tag.href = 'dashboard.php?' + params;
            return;
        }
        const link = document.createElement('a');
        link.className = tag.className;
        link.textContent = tag.textContent;
        link.href = 'dashboard.php?' + params;
        tag.replaceWith(link);
    });
    enhanceTags();
    const table = document.querySelector('.transactions-table');
    if (table) new MutationObserver(enhanceTags).observe(table, {childList:true, subtree:true});
}
