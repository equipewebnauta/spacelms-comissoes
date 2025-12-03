
/**
 * 💰 Sistema de Comissões WPLMS (Administração Completa)
 * 
 * Autor: Miguel Cezar Ferreira
 * Data: 02/12/2025
 * 
 * Descrição:
 * Este módulo implementa um sistema completo de **Gestão de Comissões do WPLMS**, permitindo
 * administração centralizada de taxas individuais, pagamentos, histórico, relatórios e controle
 * detalhado de vendas e faturamento de cursos vinculados ao WooCommerce.
 * 
 * Ele oferece ao administrador ferramentas avançadas para visualizar cursos, editar taxas de
 * comissão, registrar pagamentos, consultar o histórico completo de remunerações e aplicar
 * alterações em massa de forma simples e segura.
 * 
 * ⚙️ O que este código faz:
 * - Exibe uma tabela administrativa com todos os cursos elegíveis.
 * - Lista:
 *      • Instrutor responsável (autor do curso)
 *      • Preço e produto vinculado ao curso (vibe_product)
 *      • Taxa de comissão configurada no curso
 *      • Faturamento total gerado
 *      • Comissão acumulada a pagar
 *      • Ações de edição individual
 * - Permite ao administrador:
 *      • Editar taxa de comissão por curso
 *      • Aplicar taxa padrão a todos os cursos em lote
 *      • Registrar pagamentos efetuados aos instrutores
 *      • Editar pagamentos já realizados
 *      • Excluir entradas do histórico de pagamentos
 * - Exibe histórico completo de pagamentos com:
 *      • Instrutor
 *      • Curso
 *      • Valor pago
 *      • Data
 *      • Observações
 * 
 * 🔍 Como funciona o cálculo da comissão:
 * - Obtém todos os cursos cadastrados como “certificados”.
 * - Para cada curso:
 *      • Identifica o instrutor responsável (post_author).
 *      • Acessa o produto WooCommerce vinculado ao curso via meta "vibe_product".
 *      • Busca pedidos válidos contendo aquele produto.
 *      • Filtra pedidos por status comercial: "processing" e "completed".
 *      • Soma:
 *          - Quantidade vendida
 *          - Faturamento total do curso (line_total)
 *          - Comissão calculada com base na taxa configurada (meta: commission_rate)
 * 
 * 🧾 Sistema de Histórico:
 * - Cada pagamento registrado cria um item no histórico.
 * - Campos incluídos:
 *      • ID do instrutor
 *      • ID do curso
 *      • Valor pago
 *      • Data de pagamento
 *      • Observação opcional
 * - Itens podem ser:
 *      • Editados
 *      • Removidos
 * - O histórico é exibido em tabela com ordenação natural.
 * 
 * 🔐 Segurança da administração:
 * - Todas as ações utilizam:
 *      • wp_verify_nonce
 *      • sanitize_text_field
 *      • sanitize_textarea_field
 *      • intval() e floatval()
 * - Somente administradores podem registrar, editar ou remover pagamentos.
 * - Verificações garantem que o curso pertence realmente ao instrutor informado.
 * 
 * 🧠 Funcionamento técnico:
 * - Lê e grava informações em:
 *      • post_meta dos cursos (taxas, produto vinculado, etc.)
 *      • tabela personalizada do histórico de pagamentos
 * - Utiliza:
 *      • Filtros por categoria de curso
 *      • Loops WooCommerce para leitura de vendas
 *      • Estrutura MVC simplificada dentro do painel
 * - A interface usa:
 *      • Bootstrap 5
 *      • Modais de edição
 *      • Formulários protegidos com nonce
 * 
 * 🧩 Requisitos:
 * - WPLMS ativo e configurado.
 * - WooCommerce instalado.
 * - Cursos vinculados corretamente via meta “vibe_product”.
 * - Permissões administrativas no WordPress.
 * 
 * 💡 Possíveis melhorias:
 * - Adicionar exportação XLSX com:
 *      • Cursos por instrutor
 *      • Faturamento total
 *      • Comissões pendentes e pagas
 * - Implementar DataTables para ordenação avançada.
 * - Criar filtro por período (mês, ano, personalizado).
 * - Inserir KPIs no topo: total pago, total pendente, cursos vendidos.
 * - Adicionar painel gráfico com Chart.js.
 */








add_action('admin_menu', function() {
    add_menu_page(
        'Comissões WPLMS',
        'Comissões',
        'manage_options',
        'wplms-commissions',
        'wplms_commissions_admin_page',
        'dashicons-money-alt',
        55
    );
});



function wplms_commissions_admin_page() {
    global $wpdb;

    if (!class_exists('WooCommerce')) {
        echo '<div class="error"><p>WooCommerce não está ativo.</p></div>';
        return;
    }

    // nonce para validar ações
    $current_nonce = wp_create_nonce('wplms_commissions_action');

    echo '
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <style>
        .wplms-container { display:flex; flex-direction:column; gap:30px; width:100%; }
        .wplms-card { border-radius:12px; padding:20px; background:#fff; }
        .table-responsive { border-radius:12px; overflow:hidden; }
        input[type=number]::-webkit-inner-spin-button { opacity: 1; }
    </style>';

    echo '<div class="container-fluid mt-4 wplms-container">';
    echo '<div class="wplms-card"><h1 class="fw-bold">📊 Comissões dos Instrutores - WPLMS</h1></div>';

    /* -------------------- PROCESSAMENTO DE POSTS -------------------- */
    // todas as ações verificam nonce
    // 1) payment_update (salvar status + possivel payment_amount para histórico)
    if ( isset($_POST['payment_update'], $_POST['payment_course_id'], $_POST['wplms_nonce']) ) {
        if ( ! wp_verify_nonce($_POST['wplms_nonce'], 'wplms_commissions_action') ) {
            echo '<div class="alert alert-danger mt-3">Falha na verificação de segurança (nonce inválido).</div>';
        } else {
            $course_id = intval($_POST['payment_course_id']);

            if ( $course_id <= 0 ) {
                echo '<div class="alert alert-danger mt-3">ID do curso inválido.</div>';
            } else {
                $paid  = isset($_POST['payment_status']) ? sanitize_text_field($_POST['payment_status']) : '';
                $notes = isset($_POST['payment_notes']) ? sanitize_textarea_field($_POST['payment_notes']) : '';

                update_post_meta($course_id, 'wplms_commission_paid', $paid);
                update_post_meta($course_id, 'wplms_commission_paid_notes', $notes);

                if ( $paid === 'yes' || $paid === 'no' ) {
                    $payment_date_raw = isset($_POST['payment_date']) ? sanitize_text_field($_POST['payment_date']) : '';
                    if ( ! empty($payment_date_raw) ) {
                        $ts = strtotime($payment_date_raw);
                        $payment_date = ( $ts !== false ) ? date('Y-m-d H:i:s', $ts) : current_time('mysql');
                    } else {
                        $payment_date = current_time('mysql');
                    }
                    update_post_meta($course_id, 'wplms_commission_paid_date', $payment_date);
                }

                // se enviou um valor para registrar no histórico
                if ( isset($_POST['payment_amount']) && $_POST['payment_amount'] !== '' ) {
                    $amount = floatval( str_replace(',', '.', $_POST['payment_amount']) );
                    if ( $amount > 0 ) {
                        $history = get_post_meta($course_id, 'wplms_commission_payments_history', true);
                        if ( ! is_array($history) ) $history = [];

                        $history_date = (isset($payment_date) && !empty($payment_date)) ? $payment_date : current_time('mysql');

                        $history[] = [
                            'amount' => $amount,
                            'date'   => $history_date,
                            'note'   => $notes
                        ];

                        update_post_meta($course_id, 'wplms_commission_payments_history', $history);
                    }
                }

                echo '<div class="alert alert-success mt-3">Status de pagamento atualizado com sucesso.</div>';
            }
        }
    }

    // 2) delete_payment
    if ( isset($_POST['delete_payment'], $_POST['payment_course_id'], $_POST['payment_index'], $_POST['wplms_nonce']) ) {
        if ( ! wp_verify_nonce($_POST['wplms_nonce'], 'wplms_commissions_action') ) {
            echo '<div class="alert alert-danger mt-3">Falha na verificação de segurança (nonce inválido).</div>';
        } else {
            $course_id = intval($_POST['payment_course_id']);
            $index     = intval($_POST['payment_index']);

            if ( $course_id > 0 ) {
                $history = get_post_meta($course_id, 'wplms_commission_payments_history', true);
                if (!is_array($history)) $history = [];

                if ( isset($history[$index]) ) {
                    unset($history[$index]);
                    $history = array_values($history);
                    update_post_meta($course_id, 'wplms_commission_payments_history', $history);
                    echo '<div class="alert alert-success mt-3">Pagamento removido.</div>';
                }
            }
        }
    }

    // 3) edit_payment
    if ( isset($_POST['edit_payment'], $_POST['payment_course_id'], $_POST['payment_index'], $_POST['wplms_nonce']) ) {
        if ( ! wp_verify_nonce($_POST['wplms_nonce'], 'wplms_commissions_action') ) {
            echo '<div class="alert alert-danger mt-3">Falha na verificação de segurança (nonce inválido).</div>';
        } else {
            $course_id = intval($_POST['payment_course_id']);
            $index     = intval($_POST['payment_index']);

            if ( $course_id > 0 ) {
                $history = get_post_meta($course_id, 'wplms_commission_payments_history', true);
                if (!is_array($history)) $history = [];

                if ( isset($history[$index]) ) {
                    $edit_value = isset($_POST['payment_edit_value']) ? floatval(str_replace(',', '.', $_POST['payment_edit_value'])) : 0;
                    $edit_date_raw = isset($_POST['payment_edit_date']) ? sanitize_text_field($_POST['payment_edit_date']) : '';
                    $edit_note = isset($_POST['payment_edit_note']) ? sanitize_textarea_field($_POST['payment_edit_note']) : '';

                    $ts = strtotime($edit_date_raw);
                    $edit_date = ($ts !== false) ? date('Y-m-d H:i:s', $ts) : current_time('mysql');

                    $history[$index]['amount'] = $edit_value;
                    $history[$index]['date']   = $edit_date;
                    $history[$index]['note']   = $edit_note;

                    update_post_meta($course_id, 'wplms_commission_payments_history', $history);
                    echo '<div class="alert alert-success mt-3">Pagamento atualizado!</div>';
                }
            }
        }
    }

    // 4) add_payment (rota compatível, opcional)
    if ( isset($_POST['add_payment'], $_POST['payment_course_id'], $_POST['wplms_nonce']) ) {
        if ( ! wp_verify_nonce($_POST['wplms_nonce'], 'wplms_commissions_action') ) {
            echo '<div class="alert alert-danger mt-3">Falha na verificação de segurança (nonce inválido).</div>';
        } else {
            $course_id = intval($_POST['payment_course_id']);
            $value     = isset($_POST['payment_value']) ? floatval(str_replace(',', '.', $_POST['payment_value'])) : 0;
            $note      = isset($_POST['payment_note']) ? sanitize_textarea_field($_POST['payment_note']) : '';
            $date      = !empty($_POST['payment_value_date']) ? sanitize_text_field($_POST['payment_value_date']) : current_time('mysql');

            if ( $course_id > 0 && $value > 0 ) {
                $history = get_post_meta($course_id, 'wplms_commission_payments_history', true);
                if (!is_array($history)) $history = [];

                $ts = strtotime($date);
                $date = ($ts !== false) ? date('Y-m-d H:i:s', $ts) : current_time('mysql');

                $history[] = [
                    'amount' => $value,
                    'date'   => $date,
                    'note'   => $note
                ];

                update_post_meta($course_id, 'wplms_commission_payments_history', $history);

                echo '<div class="alert alert-success mt-3">Pagamento registrado com sucesso.</div>';
            } else {
                echo '<div class="alert alert-warning mt-3">Valor ou curso inválido para registro.</div>';
            }
        }
    }

    /* -------------------- AÇÕES DE TAXAS (também exigem nonce) -------------------- */
    if ( isset($_POST['apply_rate_selected'], $_POST['selected_courses'], $_POST['wplms_nonce']) ) {
        if ( wp_verify_nonce($_POST['wplms_nonce'], 'wplms_commissions_action') ) {
            $rate = intval($_POST['apply_rate']);
            foreach ($_POST['selected_courses'] as $course_id) {
                update_post_meta(intval($course_id), 'wplms_commission_rate', $rate);
            }
            echo '<div class="alert alert-success mt-3">Taxa aplicada aos cursos selecionados.</div>';
        }
    }

    if ( isset($_POST['save_individual_rates'], $_POST['course_commission'], $_POST['wplms_nonce']) ) {
        if ( wp_verify_nonce($_POST['wplms_nonce'], 'wplms_commissions_action') ) {
            foreach ($_POST['course_commission'] as $course_id => $rate) {
                update_post_meta(intval($course_id), 'wplms_commission_rate', intval($rate));
            }
            echo '<div class="alert alert-success mt-3">Taxas individuais salvas com sucesso.</div>';
        }
    }

    /* -------------------- FILTROS -------------------- */
    $filter_instructor = isset($_GET['instructor']) ? sanitize_text_field($_GET['instructor']) : '';
    $filter_course     = isset($_GET['course'])     ? sanitize_text_field($_GET['course'])     : '';
    $filter_status     = isset($_GET['status'])     ? sanitize_text_field($_GET['status'])     : '';

    echo '
    <div class="wplms-card mb-3">
        <h4 class="fw-bold mb-3">🔍 Filtros</h4>

        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="wplms-commissions">

            <div class="col-md-3">
                <label class="form-label">Instrutor:</label>
                <input type="text" name="instructor" value="' . esc_attr($filter_instructor) . '" class="form-control" placeholder="Nome do instrutor">
            </div>

            <div class="col-md-3">
                <label class="form-label">Curso:</label>
                <input type="text" name="course" value="' . esc_attr($filter_course) . '" class="form-control" placeholder="Nome do curso">
            </div>

            <div class="col-md-3">
                <label class="form-label">Status do Pagamento:</label>
                <select name="status" class="form-control">
                    <option value="">Todos</option>
                    <option value="yes" ' . selected($filter_status, "yes", false) . '>Pago</option>
                    <option value="no"  ' . selected($filter_status, "no", false)  . '>Não Pago</option>
                </select>
            </div>

            <div class="col-md-3 d-grid">
                <button class="btn btn-primary">Aplicar Filtros</button>
            </div>
        </form>
    </div>';

    echo '<div class="wplms-card">
            <h3>🎓 Cursos da categoria <strong>Certificados</strong></h3>
        </div>';

    /* -------------------- QUERY -------------------- */
    $per_page = 20;
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

    $query_args = [
        'post_type'      => 'course',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'post_status'    => 'publish',
        'tax_query'      => [
            [
                'taxonomy' => 'course-cat',
                'field'    => 'slug',
                'terms'    => 'certificados',
            ],
        ],
    ];

    if ($filter_course !== '') {
        $query_args['s'] = $filter_course;
    }

    if ($filter_instructor !== '') {
        $users = get_users([
            'search'         => '*' . esc_attr($filter_instructor) . '*',
            'search_columns' => ['display_name', 'user_login'],
            'fields'         => ['ID'],
        ]);

        $query_args['author__in'] = !empty($users) ? wp_list_pluck($users, 'ID') : [0];
    }

    if (!isset($query_args['meta_query'])) {
        $query_args['meta_query'] = [];
    }

    if ($filter_status === 'yes' || $filter_status === 'no') {
        $query_args['meta_query'][] = [
            'key'   => 'wplms_commission_paid',
            'value' => $filter_status,
        ];
    }

    $course_query = new WP_Query($query_args);
    $courses = $course_query->posts;

    /* -------------------- TABELA (form principal: contém actions de taxa e salvar taxas) -------------------- */
    echo '<form method="post" class="wplms-card">
    <input type="hidden" name="wplms_nonce" value="' . esc_attr($current_nonce) . '">
    <div class="table-responsive shadow">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th><input type="checkbox" id="select_all"></th>
                    <th>Instrutor</th>
                    <th>Curso</th>
                    <th>Vendas</th>
                    <th>Total Faturado</th>
                    <th>Taxa Individual (%)</th>
                    <th>Comissão Total</th>
                    <th>Status</th>
                    <th>Ver mais</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($courses as $course) {

        $course_id    = $course->ID;
        $instructor   = get_the_author_meta('display_name', $course->post_author);
        $product_id   = get_post_meta($course_id, 'vibe_product', true);
        if (!$product_id) continue;

        $course_commission = get_post_meta($course_id, 'wplms_commission_rate', true);
        if ($course_commission === '' || $course_commission === null) $course_commission = 0;

        $paid      = get_post_meta($course_id, 'wplms_commission_paid', true);
        $paid_date = get_post_meta($course_id, 'wplms_commission_paid_date', true);
        $notes     = get_post_meta($course_id, 'wplms_commission_paid_notes', true);
        if (!$notes) $notes = 'Nenhuma observação registrada.';

        $paid_normalized = $paid === 'yes' ? 'yes' : ($paid === 'no' ? 'no' : 'none');

        if ($filter_status !== '' && $filter_status !== $paid_normalized) continue;

        $order_items = $wpdb->get_results($wpdb->prepare("
            SELECT oi.order_id, oi.order_item_id
            FROM {$wpdb->prefix}woocommerce_order_items oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
                ON oi.order_item_id = oim.order_item_id
            WHERE oi.order_item_type = 'line_item'
              AND (
                    (oim.meta_key = '_product_id' AND oim.meta_value = %d)
                    OR
                    (oim.meta_key = '_course_id' AND oim.meta_value = %d)
                  )
        ", $product_id, $course_id));

        $sales_count = 0;
        $total_earned = 0.0;

        foreach ($order_items as $item_obj) {
            $order = wc_get_order($item_obj->order_id);
            if (!$order || !in_array($order->get_status(), ['processing', 'completed'])) continue;

            $item = $order->get_item($item_obj->order_item_id);
            if (!$item) continue;

            $sales_count++;

            $line_total = $item->get_total();
            if (!$line_total) $line_total = $item->get_meta('_line_total', true);

            $total_earned += (float)$line_total;
        }

        $commission_total = ($total_earned * $course_commission) / 100;

        // carregar histórico e calcular total já pago
        $history = get_post_meta($course_id, 'wplms_commission_payments_history', true);
        $history = is_array($history) ? $history : [];
        $total_paid = 0;
        foreach ($history as $h) {
            $total_paid += floatval(isset($h['amount']) ? $h['amount'] : 0);
        }
        $remaining = $commission_total - $total_paid;
        if ($remaining < 0) $remaining = 0;

        echo '
<tr>
    <td><input type="checkbox" name="selected_courses[]" value="' . esc_attr($course_id) . '" class="course_select"></td>
    <td>' . esc_html($instructor) . '</td>
    <td>' . esc_html($course->post_title) . '</td>
    <td>' . esc_html($sales_count) . '</td>
    <td>R$ ' . number_format($total_earned, 2, ',', '.') . '</td>

    <td>
        <input type="number" 
               name="course_commission[' . esc_attr($course_id) . ']" 
               value="' . esc_attr($course_commission) . '" 
               min="0" max="100" 
               class="form-control"
               style="width:90px;">
    </td>

    <td>
        <strong>Total: R$ ' . number_format($commission_total, 2, ',', '.') . '</strong><br>
        <span class="text-success">Pago: R$ ' . number_format($total_paid, 2, ',', '.') . '</span><br>
        <span class="text-danger">Falta: R$ ' . number_format($remaining, 2, ',', '.') . '</span>
    </td>

    <td>' .
        (
            $paid_normalized === 'yes'
                ? '<span class="badge bg-success">Pago</span><br><small>' . ( $paid_date ? date("d/m/Y H:i", strtotime($paid_date)) : '' ) . '</small>'
            : ($paid_normalized === 'no'
                ? '<span class="badge bg-secondary">Não pago</span>'
                : '<span class="badge bg-warning text-dark">Sem registro</span>'
            )
        )
    . '</td>

    <td>
        <button type="button" class="btn btn-sm btn-outline-primary"
                data-bs-toggle="modal" data-bs-target="#paymentModal-' . esc_attr($course_id) . '">
            Detalhes
        </button>
    </td>
</tr>';

        /* -------------------- MODAL: formulários separados para cada ação -------------------- */
        $paid_date_val = $paid_date ? date('Y-m-d\TH:i', strtotime($paid_date)) : '';

        // monta o HTML do histórico com botões de Editar/Excluir (cada ação é um form independente)
        $modal_history_html = '';
        if (!empty($history)) {
            $idx = 0;
            foreach ($history as $h) {
                $h_amount = floatval(isset($h['amount']) ? $h['amount'] : 0);
                $h_date_raw = isset($h['date']) ? $h['date'] : '';
                $h_note = isset($h['note']) ? $h['note'] : '';
                $h_date_formatted = $h_date_raw ? date("d/m/Y H:i", strtotime($h_date_raw)) : '';

                // histórico item
                $modal_history_html .= '<div class="border rounded p-2 mb-2">';
                $modal_history_html .= '<strong>Valor:</strong> R$ ' . number_format($h_amount, 2, ',', '.') . '<br>';
                $modal_history_html .= '<strong>Data:</strong> ' . esc_html($h_date_formatted) . '<br>';
                $modal_history_html .= '<strong>Obs:</strong> ' . esc_html($h_note) . '<br><br>';

                // botão editar (toggle collapse)
                $modal_history_html .= '<div class="d-flex gap-2">';
                $modal_history_html .= '<button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#editPayment' . esc_attr($course_id) . '-' . $idx . '">Editar</button>';

                // form de exclusão (independente)
                $modal_history_html .= '<form method="post" style="display:inline;" onsubmit="return confirm(\'Remover este pagamento?\');">';
                $modal_history_html .= '<input type="hidden" name="wplms_nonce" value="' . esc_attr($current_nonce) . '">';
                $modal_history_html .= '<input type="hidden" name="payment_course_id" value="' . esc_attr($course_id) . '">';
                $modal_history_html .= '<input type="hidden" name="payment_index" value="' . esc_attr($idx) . '">';
                $modal_history_html .= '<button type="submit" name="delete_payment" value="1" class="btn btn-sm btn-danger">Excluir</button>';
                $modal_history_html .= '</form>';

                $modal_history_html .= '</div>';

                // formulário de edição (collapse)
                $edit_date_val = $h_date_raw ? date('Y-m-d\TH:i', strtotime($h_date_raw)) : '';
                $modal_history_html .= '<div class="collapse mt-3" id="editPayment' . esc_attr($course_id) . '-' . $idx . '">';
                $modal_history_html .= '<form method="post" class="border rounded p-2">';
                $modal_history_html .= '<input type="hidden" name="wplms_nonce" value="' . esc_attr($current_nonce) . '">';
                $modal_history_html .= '<input type="hidden" name="payment_course_id" value="' . esc_attr($course_id) . '">';
                $modal_history_html .= '<input type="hidden" name="payment_index" value="' . esc_attr($idx) . '">';

                $modal_history_html .= '<label class="form-label fw-bold">Valor:</label>';
                $modal_history_html .= '<input type="number" step="0.01" name="payment_edit_value" value="' . esc_attr( number_format($h_amount, 2, '.', '') ) . '" class="form-control">';

                $modal_history_html .= '<label class="form-label fw-bold mt-2">Data:</label>';
                $modal_history_html .= '<input type="datetime-local" name="payment_edit_date" value="' . esc_attr($edit_date_val) . '" class="form-control">';

                $modal_history_html .= '<label class="form-label fw-bold mt-2">Observações:</label>';
                $modal_history_html .= '<textarea name="payment_edit_note" class="form-control" rows="3">' . esc_textarea($h_note) . '</textarea>';

                $modal_history_html .= '<button type="submit" name="edit_payment" value="1" class="btn btn-success mt-3">Salvar alterações</button>';
                $modal_history_html .= '</form>';
                $modal_history_html .= '</div>';

                $modal_history_html .= '</div>'; // fim item histórico

                $idx++;
            }
        } else {
            $modal_history_html = '<p class="text-muted">Nenhum pagamento registrado.</p>';
        }

        // Modal — cada modal contém formulários separados:
        echo '
        <div class="modal fade" id="paymentModal-' . esc_attr($course_id) . '" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title">Pagamento da Comissão</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">';

        // Form principal para salvar status + adicionar pagamento imediato (payment_update)
        echo '
        <form method="post" id="payment_form_' . esc_attr($course_id) . '">
            <input type="hidden" name="wplms_nonce" value="' . esc_attr($current_nonce) . '">
            <input type="hidden" name="payment_course_id" value="' . esc_attr($course_id) . '">

            <label class="form-label fw-bold">Status do Pagamento:</label>
            <select name="payment_status" class="form-control">
                <option value="no" ' . selected($paid, "no", false) . '>Não pago</option>
                <option value="yes" ' . selected($paid, "yes", false) . '>Pago</option>
            </select>

            <br>

            <label class="form-label fw-bold">Adicionar pagamento (R$):</label>
            <input type="number" step="0.01" name="payment_amount" class="form-control" placeholder="Ex: 150.00">
            <small class="text-muted">Este valor será somado ao histórico de pagamentos.</small>

            <br><br>

            <label class="form-label fw-bold">Data do pagamento:</label>
            <input type="datetime-local" name="payment_date" class="form-control" value="' . esc_attr($paid_date_val) . '">
            <small class="text-muted">Se vazio, a data atual será usada.</small>

            <br><br>

            <label class="form-label fw-bold">Anotações:</label>
            <textarea name="payment_notes" class="form-control" rows="4">' . esc_textarea($notes) . '</textarea>

            <div class="mt-3 d-flex justify-content-end gap-2">
                <button type="submit" name="payment_update" value="1" class="btn btn-success">Salvar</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </form>';

        // Espaçamento
        echo '<hr>';

        // Botão e formulário para rota Add Payment (opcional) if you still use it, but we already handle via payment_update above
        // (não é estritamente necessário porque payment_update já registra payment_amount)

        // Histórico com edição/exclusão (cada ação tem seu próprio form - já embutidos no HTML gerado acima)
        echo '<h5 class="fw-bold mt-3">Histórico de Pagamentos</h5>';
        echo $modal_history_html;

        // resumo
        echo '
        <div class="alert alert-info mt-3">
            <strong>Total da comissão:</strong> R$ ' . number_format($commission_total, 2, ',', '.') . '<br>
            <strong>Total já pago:</strong> R$ ' . number_format($total_paid, 2, ',', '.') . '<br>
            <strong>Saldo restante:</strong> R$ ' . number_format($remaining, 2, ',', '.') . '
        </div>';

        echo '

                    </div>
                </div>
            </div>
        </div>';
    }

    echo '</tbody></table></div>

        <div class="row mt-4">
            <div class="col-md-4">
                <label class="form-label fw-bold">Aplicar taxa (%) aos selecionados:</label>
                <input type="number" name="apply_rate" class="form-control" min="0" max="100" placeholder="Ex: 15">
            </div>

            <div class="col-md-4 d-flex align-items-end">
                <button class="btn btn-sm btn-outline-primary w-100" name="apply_rate_selected" value="1">Aplicar taxa aos selecionados</button>
            </div>

            <div class="col-md-4 d-flex align-items-end">
                <button class="btn btn-sm btn-outline-primary w-100" name="save_individual_rates" value="1">Salvar taxas individuais</button>
            </div>
        </div>

    </form>';

    echo "
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var selectAll = document.getElementById('select_all');
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    document.querySelectorAll('.course_select').forEach(cb => cb.checked = selectAll.checked);
                });
            }
        });
    </script>";

    $total_pages = $course_query->max_num_pages;

    if ($total_pages > 1) {
        $pagination = paginate_links([
            'base'      => add_query_arg('paged', '%#%'),
            'total'     => $total_pages,
            'current'   => $paged,
            'type'      => 'array',
        ]);

        echo '<div class="wplms-card text-center"><ul class="pagination justify-content-center">';
        foreach ($pagination as $link) {
            echo '<li class="page-item">' . str_replace('page-numbers', 'page-link', $link) . '</li>';
        }
        echo '</ul></div>';
    }

    if (function_exists('wplms_commissions_top_instructors_chart')) {
        wplms_commissions_top_instructors_chart();
    }

    echo '</div>';
}






/**
 * 📊 Exportação de Ranking de Instrutores (WPLMS + WooCommerce)
 * 
 * Autor: Miguel Cezar Ferreira
 * Data: 02/12/2025
 * 
 * Descrição:
 * Este módulo implementa um sistema completo de **Ranking de Instrutores**, capaz de exibir,
 * organizar e exportar os dados de vendas e comissões de cursos certificados no WPLMS.
 * Além disso, permite a exportação de uma **planilha XLSX**, gerada dinamicamente via AJAX,
 * contendo uma aba separada para cada instrutor.
 * 
 * ⚙️ O que este código faz:
 * - Cria um endpoint AJAX seguro para gerar e baixar uma planilha XLSX.
 * - Coleta todos os dados de vendas por curso e por instrutor usando consultas WooCommerce.
 * - Gera automaticamente uma planilha contendo:
 *      • Uma aba para cada instrutor  
 *      • Total de vendas do instrutor  
 *      • Faturamento por curso  
 *      • Taxa de comissão configurada no meta do curso  
 *      • Comissão final por curso e total geral  
 * - Exibe no painel WPLMS um Ranking de Instrutores com:
 *      • Posição no ranking  
 *      • Nome do instrutor  
 *      • Número total de vendas  
 *      • Faturamento acumulado  
 *      • Comissão total gerada  
 * - Abre modais com detalhes aprofundados de:
 *      • Vendas e faturamento por curso  
 *      • Lista completa de cursos do instrutor  
 * 
 * 🔍 Como funciona a coleta de dados:
 * - Busca todos os cursos da categoria "certificados".
 * - Para cada curso:
 *      • Identifica o instrutor responsável.
 *      • Busca o produto vinculado ao curso (meta: vibe_product).
 *      • Localiza pedidos que contenham o produto.
 *      • Filtra apenas pedidos com status "completed".
 *      • Soma:
 *          - quantidade de vendas
 *          - faturamento (line_total)
 *          - comissão (baseada na taxa configurada em cada curso)
 * 
 * 📤 Exportação XLSX:
 * - Utiliza a biblioteca PhpSpreadsheet.
 * - Cria uma aba por instrutor, contendo:
 *      • Nome do curso  
 *      • Vendas  
 *      • Faturamento  
 *      • Taxa de comissão (%)  
 *      • Comissão total (R$)  
 * - Adiciona automaticamente:
 *      • Totais consolidados por instrutor  
 *      • Formatação numérica apropriada  
 * - Arquivo baixado como: `ranking-instrutores-AAAA-MM-DD_HHMM.xlsx`
 * 
 * 🧩 Requisitos:
 * - WPLMS configurado com cursos usando vibe_product.
 * - WooCommerce instalado e ativo.
 * - Biblioteca PhpSpreadsheet disponível em:
 *        wp-content/vendor/autoload.php
 *        ou wp-content/plugins/vendor/autoload.php
 * 
 * 🧠 Funcionamento técnico:
 * - Endpoint AJAX registrado com:  
 *     • wp_ajax_baixar_planilha_ranking_instructors  
 *     • wp_ajax_nopriv_baixar_planilha_ranking_instructors  
 * - Usa JSON para enviar detalhes para os modais no frontend.
 * - Usa PhpSpreadsheet para criação das abas de instrutores.
 * - Ordena os instrutores por número total de vendas.
 * - Garante que cursos sem vendas sejam identificados como "Nenhuma venda".
 * 
 * 💡 Possíveis melhorias:
 * - Permitir filtros por período, categoria ou instrutor específico.
 * - Adicionar opção de exportar PDF além de XLSX.
 * - Criar gráfico de desempenho no próprio painel.
 * - Integrar DataTables para pesquisa, ordenação e paginação avançada.
 */
// === 1) Hook para registrar endpoint AJAX (download) ===
add_action('wp_ajax_baixar_planilha_ranking_instructors', 'baixar_planilha_ranking_instructors');
add_action('wp_ajax_nopriv_baixar_planilha_ranking_instructors', 'baixar_planilha_ranking_instructors');

/**
 * Endpoint que gera e envia o XLSX com uma aba por instrutor.
 */
function baixar_planilha_ranking_instructors() {
    // Coletar dados (reutiliza a função abaixo)
    $dados = wplms_coletar_dados_instrutores();

    // Tenta carregar PhpSpreadsheet (procura autoload no wp-content/vendor ou plugin/vendor)
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        // tentativa padrão (wp-content/vendor)
        if (file_exists(WP_CONTENT_DIR . '/vendor/autoload.php')) {
            require_once WP_CONTENT_DIR . '/vendor/autoload.php';
        } elseif (file_exists( WP_PLUGIN_DIR . '/vendor/autoload.php')) {
            require_once WP_PLUGIN_DIR . '/vendor/autoload.php';
        }
    }

    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        // Se não está disponível, informar claramente
        wp_die('<strong>PhpSpreadsheet não está disponível.</strong><br>Instale <code>phpoffice/phpspreadsheet</code> via Composer (por exemplo: <code>composer require phpoffice/phpspreadsheet</code>) no diretório do seu plugin ou em <code>wp-content/vendor</code>.');
    }

    // Agora podemos gerar a planilha
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    // remover aba padrão
    $sheetCount = $spreadsheet->getSheetCount();
    for ($i = 0; $i < $sheetCount; $i++) {
        $spreadsheet->removeSheetByIndex(0);
    }

    // Criar abas por instrutor
    foreach ($dados as $instructor_id => $info) {
        // Nome de folha: máximo 31 chars e sem caracteres inválidos
        $safe_name = preg_replace('/[:\\\\\/\?\*\[\]]+/', '_', $info['name']);
        $safe_name = mb_substr($safe_name, 0, 31);

        $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $safe_name);
        $spreadsheet->addSheet($sheet);

        // Cabeçalho
        $sheet->setCellValue('A1', 'Curso');
        $sheet->setCellValue('B1', 'Vendas');
        $sheet->setCellValue('C1', 'Faturamento');
        $sheet->setCellValue('D1', 'Taxa (%)');
        $sheet->setCellValue('E1', 'Comissão');

        $row = 2;
        $total_faturado = 0;
        $total_comissao = 0;

        foreach ($info['courses'] as $course_id => $course) {
            if ($course['sales'] <= 0) continue;

            $sheet->setCellValue("A{$row}", $course['name']);
            $sheet->setCellValue("B{$row}", (int)$course['sales']);
            $sheet->setCellValue("C{$row}", (float)$course['amount']);
            $sheet->setCellValue("D{$row}", (float)$course['commission_rate']);
            $sheet->setCellValue("E{$row}", (float)$course['commission_total']);

            $total_faturado += (float)$course['amount'];
            $total_comissao += (float)$course['commission_total'];
            $row++;
        }

        // Totais abaixo dos cursos (linha em branco antes)
        if ($row === 2) {
            // nenhum curso com vendas, escreve uma linha dizendo "Nenhuma venda"
            $sheet->setCellValue("A{$row}", 'Nenhuma venda');
            $row++;
        }

        $row++; // linha em branco
        $sheet->setCellValue("A{$row}", 'Total Faturado:');
        $sheet->setCellValue("B{$row}", (float)$total_faturado);

        $row++;
        $sheet->setCellValue("A{$row}", 'Total Comissão:');
        $sheet->setCellValue("B{$row}", (float)$total_comissao);

        // Formatação numérica (opcional, deixa como número)
        $sheet->getStyle("B2:B{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("C2:C{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("E2:E{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
    }

    // Definir aba ativa para a primeira (se existir)
    if ($spreadsheet->getSheetCount() > 0) {
        $spreadsheet->setActiveSheetIndex(0);
    }

    // Enviar para download
    $filename = 'ranking-instrutores-' . date('Y-m-d_Hi') . '.xlsx';

    // Cabeçalhos
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save("php://output");
    exit;
}


// === 2) Função que coleta os dados (reaproveita exatamente a lógica original) ===
function wplms_coletar_dados_instrutores() {
    global $wpdb;

    // Buscar cursos da categoria certificados
    $courses = get_posts([
        'post_type'      => 'course',
        'posts_per_page' => -1,
        'tax_query'      => [
            [
                'taxonomy' => 'course-cat',
                'field'    => 'slug',
                'terms'    => 'certificados',
            ],
        ],
    ]);

    $instructor_data = [];

    foreach ($courses as $course) {

        $course_id     = $course->ID;
        $instructor_id = $course->post_author;
        $instructor    = get_the_author_meta('display_name', $instructor_id);

        if (!isset($instructor_data[$instructor_id])) {
            $instructor_data[$instructor_id] = [
                'name'   => $instructor,
                'sales'  => 0,
                'amount' => 0.0,
                'commission_total' => 0.0,
                'courses' => []
            ];
        }

        // Buscar taxa de comissão do curso
        $commission_rate = (float) get_post_meta($course_id, 'wplms_commission_rate', true);
        if (!$commission_rate) $commission_rate = 0;

        // Registrar curso
        $instructor_data[$instructor_id]['courses'][$course_id] = [
            'name'             => get_the_title($course_id),
            'sales'            => 0,
            'amount'           => 0.0,
            'commission_rate'  => $commission_rate,
            'commission_total' => 0.0,
        ];

        $product_id = get_post_meta($course_id, 'vibe_product', true);
        if (!$product_id) continue;

        // Buscar pedidos que contenham o produto/curso
        $order_items = $wpdb->get_results($wpdb->prepare("
            SELECT oi.order_id, oi.order_item_id
            FROM {$wpdb->prefix}woocommerce_order_items oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
                 ON oi.order_item_id = oim.order_item_id
            WHERE oi.order_item_type = 'line_item'
              AND (
                    (oim.meta_key = '_product_id' AND oim.meta_value = %d)
                 OR (oim.meta_key = '_course_id' AND oim.meta_value = %d)
              )
        ", $product_id, $course_id));

        foreach ($order_items as $item_obj) {
            $order = wc_get_order($item_obj->order_id);

            if (!$order || !in_array($order->get_status(), [ 'completed']))
                continue;

            $item = $order->get_item($item_obj->order_item_id);
            if (!$item) continue;

            $line_total = (float)$item->get_total();
            if (!$line_total)
                $line_total = (float)$item->get_meta('_line_total', true);

            // Geral
            $instructor_data[$instructor_id]['sales']++;
            $instructor_data[$instructor_id]['amount'] += $line_total;

            // Curso
            $instructor_data[$instructor_id]['courses'][$course_id]['sales']++;
            $instructor_data[$instructor_id]['courses'][$course_id]['amount'] += $line_total;

            // Comissão do curso
            $commission_value = ($line_total * $commission_rate) / 100;

            // Registrar comissão por curso
            $instructor_data[$instructor_id]['courses'][$course_id]['commission_total'] += $commission_value;

            // Total geral de comissão
            $instructor_data[$instructor_id]['commission_total'] += $commission_value;
        }
    }

    // Ordenar instrutores por vendas
    uasort($instructor_data, fn($a, $b) => $b['sales'] <=> $a['sales']);

    return $instructor_data;
}


// === 3) Função original de exibição, agora refatorada para incluir botão de download ===
function wplms_commissions_top_instructors_chart() {

    $instructor_data = wplms_coletar_dados_instrutores();
    $details_json = json_encode($instructor_data, JSON_UNESCAPED_UNICODE);

    $download_url = admin_url('admin-ajax.php?action=baixar_planilha_ranking_instructors');

    echo '
<style>
    /* Melhor altura para scroll confortável */
    .modal-body-scroll {
        max-height: 70vh;
        overflow-y: auto;
        padding: 1.2rem;
    }
</style>

<div class="wplms-card mt-4 p-3 shadow-sm rounded bg-white">
    <h3 class="fw-bold mb-3">🏆 Ranking de Instrutores</h3>

    <div class="d-flex justify-content-end mb-3">
        <a class="btn btn-success btn-sm" href="' . esc_url($download_url) . '">
            📥 Baixar Planilha de Ranking
        </a>
    </div>

    <table class="table table-hover align-middle table-striped">
        <thead class="table-dark">
            <tr>
                <th>#</th>
                <th>Instrutor</th>
                <th>Vendas</th>
                <th>Faturamento</th>
                <th>Comissão Total</th>
            </tr>
        </thead>
        <tbody>';

    $pos = 1;
    foreach ($instructor_data as $id => $data) {
        echo '
            <tr>
                <td><b>' . $pos . 'º</b></td>
                <td>
                    <a href="#" class="instrutor-link fw-semibold text-primary"
                       data-id="' . esc_attr($id) . '"
                       data-nome="' . esc_attr($data['name']) . '">
                        ' . esc_html($data['name']) . '
                    </a>
                </td>
                <td>' . intval($data['sales']) . '</td>
                <td><b>R$ ' . number_format($data['amount'], 2, ',', '.') . '</b></td>
                <td><b>R$ ' . number_format($data['commission_total'], 2, ',', '.') . '</b></td>
            </tr>';
        $pos++;
    }

    echo '
        </tbody>
    </table>
</div>

<!-- MODAL PRINCIPAL -->
<div class="modal fade" id="instrutorMainModal" tabindex="-1">
    <div class="modal-dialog modal-md modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="instrutorMainTitle"></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-4">
                <button type="button" class="btn btn-primary w-100 mb-3 py-3" id="btnVerVendas">
                    📊 Ver Vendas e Faturamento
                </button>
                <button type="button" class="btn btn-secondary w-100 py-3" id="btnVerCursos">
                    📚 Ver Cursos do Instrutor
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL VENDAS -->
<div class="modal fade" id="modalVendas" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Vendas, Faturamento e Comissão</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body modal-body-scroll" id="conteudoVendas"></div>
        </div>
    </div>
</div>

<!-- MODAL CURSOS -->
<div class="modal fade" id="modalCursos" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title">Cursos do Instrutor</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body modal-body-scroll" id="conteudoCursos"></div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {

    const detalhes = ' . $details_json . ';

    let instrutorSelecionado = null;
    let instrutorIdSelecionado = null;

    // Abrir modal principal ao clicar no instrutor
    document.querySelectorAll(".instrutor-link").forEach(link => {
        link.addEventListener("click", function(e) {
            e.preventDefault();

            instrutorSelecionado = this.dataset.nome;
            instrutorIdSelecionado = this.dataset.id;

            document.getElementById("instrutorMainTitle").innerText =
                "Detalhes — " + instrutorSelecionado;

            new bootstrap.Modal(document.getElementById("instrutorMainModal")).show();
        });
    });

    // MODAL VENDAS
    document.getElementById("btnVerVendas").addEventListener("click", function() {

        const cursos = detalhes[instrutorIdSelecionado].courses;

        let html = `
            <h4 class="fw-bold mb-3">${instrutorSelecionado}</h4>
            <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Curso</th>
                        <th>Vendas</th>
                        <th>Faturamento</th>
                        <th>Taxa (%)</th>
                        <th>Comissão</th>
                    </tr>
                </thead>
                <tbody>`;

        let total = 0;
        let total_comissao = 0;

        for (const id in cursos) {
            const c = cursos[id];
            if (c.sales <= 0) continue;

            total += parseFloat(c.amount);
            total_comissao += parseFloat(c.commission_total);

            html += `
                <tr>
                    <td>${c.name}</td>
                    <td>${c.sales}</td>
                    <td><b>R$ ${c.amount.toLocaleString("pt-BR",{minimumFractionDigits:2})}</b></td>
                    <td>${c.commission_rate}%</td>
                    <td><b>R$ ${c.commission_total.toLocaleString("pt-BR",{minimumFractionDigits:2})}</b></td>
                </tr>`;
        }

        html += `
                </tbody>
            </table>
            </div>

            <div class="mt-4 p-3 bg-light rounded border">
                <h5 class="mb-2"><b>Total Faturado:</b>
                    R$ ${total.toLocaleString("pt-BR",{minimumFractionDigits:2})}
                </h5>
                <h5><b>Total de Comissão:</b>
                    R$ ${total_comissao.toLocaleString("pt-BR",{minimumFractionDigits:2})}
                </h5>
            </div>`;

        document.getElementById("conteudoVendas").innerHTML = html;

        new bootstrap.Modal(document.getElementById("modalVendas")).show();
    });

    // MODAL CURSOS
    document.getElementById("btnVerCursos").addEventListener("click", function() {

        const cursos = detalhes[instrutorIdSelecionado].courses;

        let html = `
            <h4 class="fw-bold mb-3">${instrutorSelecionado}</h4>
            <ul class="list-group">`;

        for (const id in cursos) {
            html += `<li class="list-group-item d-flex justify-content-between align-items-center">
                        ${cursos[id].name}
                        <span class="badge bg-primary rounded-pill">${cursos[id].sales ?? 0} vendas</span>
                     </li>`;
        }

        html += `</ul>`;

        document.getElementById("conteudoCursos").innerHTML = html;

        new bootstrap.Modal(document.getElementById("modalCursos")).show();
    });

});
</script>
';
} 