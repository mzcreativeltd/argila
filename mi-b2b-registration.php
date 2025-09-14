<?php
/**
 * Plugin Name: B2B Argila
 * Description: System rejestracji i weryfikacji klientów B2B
 * Version: 1.4
 * Author: Mariusz Zwolak MZ CREATIVE LTD
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

// Tworzenie tabeli w bazie danych przy aktywacji wtyczki
register_activation_hook(__FILE__, 'mi_b2b_create_tables');
function mi_b2b_create_tables() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'b2b_registrations';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        full_name varchar(255) NOT NULL,
        company_name varchar(255) NOT NULL,
        nip varchar(20) NOT NULL,
        contact_person varchar(255) NOT NULL,
        email varchar(255) NOT NULL,
        phone varchar(50) NOT NULL,
        business_category text NOT NULL,
        address text NOT NULL,
        city varchar(100) NOT NULL,
        postal_code varchar(20) NOT NULL,
        website varchar(255) DEFAULT '',
        message text,
        terms_accepted tinyint(1) DEFAULT 0,
        status varchar(20) DEFAULT 'pending',
        registration_date datetime DEFAULT CURRENT_TIMESTAMP,
        approval_date datetime DEFAULT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Dodanie roli B2B (jeśli jeszcze nie istnieje)
    add_role('b2b', 'B2B', ['read' => true]);

    // Sprawdzenie czy tabela została utworzona
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    if (!$table_exists) {
        error_log('B2B Plugin: Failed to create table ' . $table_name);
    }
}

// Funkcja sprawdzająca i aktualizująca strukturę tabeli
add_action('admin_init', 'mi_b2b_update_table_structure');
function mi_b2b_update_table_structure() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'b2b_registrations';
    
    // Sprawdź czy tabela istnieje
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    
    if ($table_exists) {
        // Sprawdź czy kolumna full_name istnieje
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'full_name'");
        
        if (!$column_exists) {
            // Dodaj brakującą kolumnę
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN full_name VARCHAR(255) NOT NULL AFTER id");
            error_log('B2B Plugin: Added missing full_name column');
        }
        
        // Sprawdź czy kolumna business_category istnieje
        $category_column = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'business_category'");

        if (!$category_column) {
            // Dodaj brakującą kolumnę
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN business_category TEXT NOT NULL AFTER phone");
            error_log('B2B Plugin: Added missing business_category column');
        }

        // Sprawdź czy kolumna terms_accepted istnieje
        $terms_column = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'terms_accepted'");

        if (!$terms_column) {
            // Dodaj brakującą kolumnę
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN terms_accepted TINYINT(1) DEFAULT 0 AFTER message");
            error_log('B2B Plugin: Added missing terms_accepted column');
        }
    }
}

// Funkcja sprawdzająca czy tabela istnieje (do debugowania)
add_action('admin_init', 'mi_b2b_check_table');
function mi_b2b_check_table() {
    if (isset($_GET['b2b_check_db'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'b2b_registrations';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        
        if (!$table_exists) {
            mi_b2b_create_tables();
            wp_die('Tabela została utworzona. <a href="' . admin_url() . '">Powrót do panelu</a>');
        } else {
            // Sprawdź strukturę tabeli
            $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
            $column_names = array();
            foreach ($columns as $column) {
                $column_names[] = $column->Field;
            }
            
            $missing_columns = array();
            $required_columns = array('id', 'full_name', 'company_name', 'nip', 'contact_person', 'email', 'phone', 'business_category', 'address', 'city', 'postal_code', 'website', 'message', 'terms_accepted', 'status', 'registration_date', 'approval_date');
            
            foreach ($required_columns as $req_col) {
                if (!in_array($req_col, $column_names)) {
                    $missing_columns[] = $req_col;
                }
            }
            
            if (!empty($missing_columns)) {
                // Usuń starą tabelę i utwórz nową
                $wpdb->query("DROP TABLE IF EXISTS $table_name");
                mi_b2b_create_tables();
                wp_die('Tabela została zresetowana i utworzona ponownie. Brakujące kolumny: ' . implode(', ', $missing_columns) . '<br><a href="' . admin_url() . '">Powrót do panelu</a>');
            } else {
                wp_die('Tabela istnieje i ma wszystkie wymagane kolumny.<br>Kolumny: ' . implode(', ', $column_names) . '<br><a href="' . admin_url() . '">Powrót do panelu</a>');
            }
        }
    }
}

// Rejestracja ustawień wtyczki
add_action('admin_init', 'mi_b2b_register_settings');
function mi_b2b_register_settings() {
    register_setting('mi-b2b-settings-group', 'mi_b2b_notification_email', 'sanitize_email');
}

// Shortcode dla formularza rejestracyjnego
add_shortcode('b2b_registration_form', 'mi_b2b_registration_form');
function mi_b2b_registration_form() {
    ob_start();
    ?>
    <style>
        .b2b-form {
            max-width: 600px;
            margin: 20px auto;
            padding: 30px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            font-family: inherit;
        }
        .b2b-form .form-group {
            margin-bottom: 20px;
        }
        .b2b-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: var(--wp--preset--color--contrast, #333);
            font-family: inherit;
        }
        .b2b-form input[type="text"],
        .b2b-form input[type="email"],
        .b2b-form input[type="tel"],
        .b2b-form textarea,
        .b2b-form select {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--wp--preset--color--contrast-3, #ddd);
            border-radius: 4px;
            font-size: 16px;
            background: var(--wp--preset--color--base, #fff);
            color: var(--wp--preset--color--contrast, #333);
            font-family: inherit;
        }
        .b2b-form select {
            height: auto;
            padding: 10px;
        }
        .b2b-form select option {
            padding: 8px 12px;
            line-height: 1.4;
        }
        .b2b-form select option:hover {
            background: var(--wp--preset--color--primary, #f0f0f0);
        }
        .b2b-form textarea {
            resize: vertical;
            min-height: 100px;
        }
        .b2b-form button {
            display: block;
            margin: 20px auto 0;
            background: var(--wp--preset--color--primary, #074F50);
            color: var(--wp--preset--color--base, white);
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
            font-weight: bold;
        }
        .b2b-form button:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        .b2b-message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .b2b-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .b2b-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .required {
            color: #e74c3c;
        }
    </style>

    <div class="b2b-form">
        <h2>Rejestracja konta B2B</h2>
        <div id="b2b-form-message"></div>
        
        <form id="b2b-registration" method="post">
            <?php wp_nonce_field('b2b_registration_nonce', 'b2b_nonce'); ?>
            
            <div class="form-group">
                <label>Imię i nazwisko <span class="required">*</span></label>
                <input type="text" name="full_name" required>
            </div>
            
            <div class="form-group">
                <label>Nazwa firmy <span class="required">*</span></label>
                <input type="text" name="company_name" required>
            </div>
            
            <div class="form-group">
                <label>NIP <span class="required">*</span></label>
                <input type="text" name="nip" required pattern="[0-9]{10}" title="NIP powinien składać się z 10 cyfr">
            </div>
            
            <div class="form-group">
                <label>Osoba kontaktowa <span class="required">*</span></label>
                <input type="text" name="contact_person" required>
            </div>
            
            <div class="form-group">
                <label>Email <span class="required">*</span></label>
                <input type="email" name="email" required>
            </div>
            
            <div class="form-group">
                <label>Telefon <span class="required">*</span></label>
                <input type="tel" name="phone" required>
            </div>

            <div class="form-group">
                <label>Adres <span class="required">*</span></label>
                <input type="text" name="address" required>
            </div>
            
            <div class="form-group">
                <label>Miasto <span class="required">*</span></label>
                <input type="text" name="city" required>
            </div>
            
            <div class="form-group">
                <label>Kod pocztowy <span class="required">*</span></label>
                <input type="text" name="postal_code" required pattern="[0-9]{2}-[0-9]{3}" title="Format: 00-000">
            </div>
            
            <div class="form-group">
                <label>Strona internetowa</label>
                <input type="text" name="website">
            </div>


            <div class="form-group">
                <label>Dodatkowe informacje</label>
                <textarea name="message"></textarea>
            </div>

            <div class="form-group">
                <label><input type="checkbox" name="terms_accepted" required> Oświadczam, że zapoznałem się z <a href="https://argilaamazonia.pl/regulamin-programu-partnerskiego-b2b-marki-argila-amazonia/" target="_blank">regulaminem programu partnerskiego B2B marki Argila Amazonia</a> i go akceptuję</label>
            </div>

            <button type="submit">Zarejestruj się jako partner B2B</button>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#b2b-registration').on('submit', function(e) {
            e.preventDefault();
            
            var formData = $(this).serialize();
            formData += '&action=b2b_registration_submit';
            
            // Pokazanie ładowania
            var submitBtn = $(this).find('button[type="submit"]');
            var originalText = submitBtn.text();
            submitBtn.prop('disabled', true).text('Wysyłanie...');
            
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    submitBtn.prop('disabled', false).text(originalText);
                    
                    if (response.success) {
                        $('#b2b-form-message').html('<div class="b2b-message b2b-success">' + response.data.message + '</div>');
                        $('#b2b-registration')[0].reset();
                        // Przewiń do góry formularza
                        $('html, body').animate({
                            scrollTop: $('.b2b-form').offset().top - 100
                        }, 500);
                    } else {
                        $('#b2b-form-message').html('<div class="b2b-message b2b-error">' + response.data.message + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    submitBtn.prop('disabled', false).text(originalText);
                    console.error('AJAX Error:', status, error);
                    console.error('Response:', xhr.responseText);
                    $('#b2b-form-message').html('<div class="b2b-message b2b-error">Błąd połączenia z serwerem. Szczegóły: ' + error + '</div>');
                }
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

// AJAX handler dla formularza
add_action('wp_ajax_b2b_registration_submit', 'mi_b2b_handle_registration');
add_action('wp_ajax_nopriv_b2b_registration_submit', 'mi_b2b_handle_registration');

function mi_b2b_handle_registration() {
    // Sprawdzenie nonce
    if (!isset($_POST['b2b_nonce']) || !wp_verify_nonce($_POST['b2b_nonce'], 'b2b_registration_nonce')) {
        wp_send_json_error(['message' => 'Błąd bezpieczeństwa. Odśwież stronę i spróbuj ponownie.']);
        return;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'b2b_registrations';
    
    // Walidacja danych
    $required_fields = ['full_name', 'company_name', 'nip', 'contact_person', 'email', 'phone', 'address', 'city', 'postal_code'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            wp_send_json_error(['message' => 'Wszystkie pola oznaczone * są wymagane. Brakuje pola: ' . $field]);
            return;
        }
    }

    // Sprawdzenie akceptacji regulaminu
    if (!isset($_POST['terms_accepted'])) {
        wp_send_json_error(['message' => 'Musisz zaakceptować regulamin.']);
        return;
    }
    
    // Sprawdzenie czy email już istnieje
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE email = %s",
        sanitize_email($_POST['email'])
    ));
    
    if ($existing > 0) {
        wp_send_json_error(['message' => 'Ten adres email jest już zarejestrowany']);
        return;
    }
    
    // Zapisanie do bazy
    $data = [
        'full_name' => sanitize_text_field($_POST['full_name']),
        'company_name' => sanitize_text_field($_POST['company_name']),
        'nip' => sanitize_text_field($_POST['nip']),
        'contact_person' => sanitize_text_field($_POST['contact_person']),
        'email' => sanitize_email($_POST['email']),
        'phone' => sanitize_text_field($_POST['phone']),
        'business_category' => '',
        'address' => sanitize_text_field($_POST['address']),
        'city' => sanitize_text_field($_POST['city']),
        'postal_code' => sanitize_text_field($_POST['postal_code']),
        'website' => isset($_POST['website']) ? sanitize_text_field($_POST['website']) : '',
        'message' => isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '',
        'terms_accepted' => isset($_POST['terms_accepted']) ? 1 : 0,
        'status' => 'pending'
    ];

    $result = $wpdb->insert(
        $table_name,
        $data
    );
    
    if ($result === false) {
        // Logowanie błędu
        error_log('B2B Registration Error: ' . $wpdb->last_error);
        wp_send_json_error(['message' => 'Błąd bazy danych: ' . $wpdb->last_error]);
        return;
    }
    
    if ($result) {
        // Wysłanie emaili
        mi_b2b_send_notification_emails($data);
        
        wp_send_json_success(['message' => 'Dziękujemy za rejestrację! Otrzymasz email z potwierdzeniem. Zweryfikujemy Twoje dane i skontaktujemy się wkrótce.']);
    } else {
        wp_send_json_error(['message' => 'Wystąpił nieoczekiwany błąd. Spróbuj ponownie później.']);
    }
}

// Funkcja wysyłania emaili
function mi_b2b_send_notification_emails($data) {
    $admin_email = get_option('mi_b2b_notification_email', get_option('admin_email'));
    
    // Email do administratora
    $admin_subject = 'Nowa rejestracja B2B - ' . $data['company_name'];
    $admin_message = "Nowa firma zgłosiła się do programu B2B:\n\n";
    $admin_message .= "Imię i nazwisko: " . $data['full_name'] . "\n";
    $admin_message .= "Nazwa firmy: " . $data['company_name'] . "\n";
    $admin_message .= "NIP: " . $data['nip'] . "\n";
    $admin_message .= "Osoba kontaktowa: " . $data['contact_person'] . "\n";
    $admin_message .= "Email: " . $data['email'] . "\n";
    $admin_message .= "Telefon: " . $data['phone'] . "\n";
    $admin_message .= "Adres: " . $data['address'] . ", " . $data['postal_code'] . " " . $data['city'] . "\n";
    $admin_message .= "Akceptacja regulaminu: " . (!empty($data['terms_accepted']) ? 'tak' : 'nie') . "\n";
    if (!empty($data['website'])) {
        $admin_message .= "Strona: " . $data['website'] . "\n";
    }
    if (!empty($data['message'])) {
        $admin_message .= "\nDodatkowe informacje:\n" . $data['message'];
    }
    $admin_message .= "\n\nZaloguj się do panelu administracyjnego, aby zaakceptować lub odrzucić zgłoszenie.";

    $headers = [ 'From: Argila <biuro@argilaamazonia.pl>' ];
    wp_mail($admin_email, $admin_subject, $admin_message, $headers);
    
    // Email do klienta
    $client_subject = 'Potwierdzenie rejestracji B2B - Argila Amazonia';
    $client_message = "Szanowni Państwo,\n\n";
    $client_message .= "Dziękujemy za zgłoszenie do programu B2B w Argila Amazonia.\n\n";
    $client_message .= "Otrzymaliśmy następujące dane:\n";
    $client_message .= "Imię i nazwisko: " . $data['full_name'] . "\n";
    $client_message .= "Nazwa firmy: " . $data['company_name'] . "\n";
    $client_message .= "NIP: " . $data['nip'] . "\n";
    $client_message .= "Osoba kontaktowa: " . $data['contact_person'] . "\n";
    $client_message .= "\n";
    $client_message .= "Weryfikujemy wszystkie zgłoszenia. Po pozytywnej weryfikacji otrzymają Państwo email z potwierdzeniem aktywacji konta B2B.\n\n";
    $client_message .= "W razie pytań prosimy o kontakt.\n\n";
    $client_message .= "Z poważaniem,\nZespół Argila Amazonia";

    wp_mail($data['email'], $client_subject, $client_message, $headers);
}

// Dodanie menu w panelu administracyjnym
add_action('admin_menu', 'mi_b2b_admin_menu');
function mi_b2b_admin_menu() {
    add_menu_page(
        'Rejestracje B2B dla Argila Amazonia',
        'B2B Argila',
        'manage_options',
        'b2b-registrations',
        'mi_b2b_admin_page',
        'dashicons-groups',
        30
    );
}

// Strona administracyjna
function mi_b2b_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'b2b_registrations';
    
    // Obsługa akcji akceptacji/odrzucenia/usunięcia/edycji
    if (isset($_GET['action']) && isset($_GET['id']) && isset($_GET['_wpnonce'])) {
        if (wp_verify_nonce($_GET['_wpnonce'], 'b2b_action_nonce')) {
            $id = intval($_GET['id']);
            $registration = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));

            if ($_GET['action'] == 'approve') {
                $wpdb->update(
                    $table_name,
                    ['status' => 'approved', 'approval_date' => current_time('mysql')],
                    ['id' => $id]
                );
                $password = mi_b2b_create_wp_user($registration);
                mi_b2b_send_approval_email($registration, $password);
                echo '<div class="notice notice-success"><p>Zgłoszenie zostało zaakceptowane!</p></div>';
            } elseif ($_GET['action'] == 'reject') {
                $wpdb->update(
                    $table_name,
                    ['status' => 'rejected', 'approval_date' => null],
                    ['id' => $id]
                );
                echo '<div class="notice notice-info"><p>Zgłoszenie zostało odrzucone.</p></div>';
            } elseif ($_GET['action'] == 'delete') {
                $wpdb->delete(
                    $table_name,
                    ['id' => $id]
                );
                echo '<div class="notice notice-success"><p>Zgłoszenie zostało usunięte.</p></div>';
            } elseif ($_GET['action'] == 'setrole' && isset($_GET['role'])) {
                $role = sanitize_text_field($_GET['role']);
                $editable_roles = array_keys(get_editable_roles());

                if (in_array($role, $editable_roles)) {
                    $user = get_user_by('email', $registration->email);

                    if ($user) {
                        $user->set_role($role);
                        echo '<div class="notice notice-success"><p>Rola użytkownika została zmieniona.</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>Nie znaleziono użytkownika.</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error"><p>Nieprawidłowa rola.</p></div>';
                }
            } elseif ($_GET['action'] == 'edit') {
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    check_admin_referer('b2b_edit_registration');
                    $data = [
                        'full_name'        => sanitize_text_field($_POST['full_name']),
                        'company_name'     => sanitize_text_field($_POST['company_name']),
                        'nip'              => sanitize_text_field($_POST['nip']),
                        'contact_person'   => sanitize_text_field($_POST['contact_person']),
                        'email'            => sanitize_email($_POST['email']),
                        'phone'            => sanitize_text_field($_POST['phone']),
                        'business_category'=> sanitize_text_field($_POST['business_category']),
                        'address'          => sanitize_text_field($_POST['address']),
                        'city'             => sanitize_text_field($_POST['city']),
                        'postal_code'      => sanitize_text_field($_POST['postal_code']),
                        'website'          => esc_url_raw($_POST['website']),
                        'message'          => sanitize_textarea_field($_POST['message']),
                    ];

                    $wpdb->update($table_name, $data, ['id' => $id]);

                    if ($user = get_user_by('email', $registration->email)) {
                        $names = explode(' ', $data['full_name'], 2);
                        $first_name = $names[0];
                        $last_name  = isset($names[1]) ? $names[1] : '';
                        wp_update_user([
                            'ID'           => $user->ID,
                            'user_email'   => $data['email'],
                            'first_name'   => $first_name,
                            'last_name'    => $last_name,
                            'display_name' => $data['full_name'],
                        ]);
                    }

                    $registration = (object) array_merge((array) $registration, $data);
                    echo '<div class="notice notice-success"><p>Dane klienta zostały zaktualizowane.</p></div>';
                }

                ?>
                <div class="wrap">
                    <h1>Edytuj klienta</h1>
                    <form method="post">
                        <?php wp_nonce_field('b2b_edit_registration'); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="full_name">Imię i nazwisko</label></th>
                                <td><input type="text" id="full_name" name="full_name" value="<?php echo esc_attr($registration->full_name); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="company_name">Nazwa firmy</label></th>
                                <td><input type="text" id="company_name" name="company_name" value="<?php echo esc_attr($registration->company_name); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="nip">NIP</label></th>
                                <td><input type="text" id="nip" name="nip" value="<?php echo esc_attr($registration->nip); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="contact_person">Osoba kontaktowa</label></th>
                                <td><input type="text" id="contact_person" name="contact_person" value="<?php echo esc_attr($registration->contact_person); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="email">Email</label></th>
                                <td><input type="email" id="email" name="email" value="<?php echo esc_attr($registration->email); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="phone">Telefon</label></th>
                                <td><input type="text" id="phone" name="phone" value="<?php echo esc_attr($registration->phone); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="business_category">Kategoria działalności</label></th>
                                <td>
                                    <select id="business_category" name="business_category">
                                        <?php
                                        $categories_map = [
                                            'salon_fryzjerski' => 'Salon fryzjerski',
                        'barber_shop' => 'Barber shop',
                        'gabinet_trychologiczny' => 'Gabinet trychologiczny',
                        'gabinet_kosmetyczny' => 'Gabinet kosmetyczny',
                        'szkola_akademia_fryzjerska' => 'Szkoła / akademia fryzjerska',
                        'pracownia_makijazu_wizazu' => 'Pracownia makijażu / wizażu',
                        'hurtownia_fryzjerska_kosmetyczna' => 'Hurtownia fryzjerska / kosmetyczna',
                        'drogeria' => 'Drogeria',
                        'sklep_stacjonarny' => 'Sklep stacjonarny',
                        'sklep_internetowy' => 'Sklep internetowy',
                        'influencer_tworca_internetowy' => 'Influencer / twórca internetowy',
                        'organizator_eventow' => 'Organizator eventów',
                        'klub_fitness_joga' => 'Klub fitness, joga',
                        'silownia' => 'Siłownia',
                        'inny_obiekt_sportowy' => 'Inny obiekt sportowy',
                        'hotel' => 'Hotel',
                        'spa_salon_masazu' => 'Spa / Salon masażu',
                        'inne' => 'Inne...'
                                        ];
                                        foreach ($categories_map as $key => $label) {
                                            echo '<option value="' . esc_attr($key) . '" ' . selected($registration->business_category, $key, false) . '>' . esc_html($label) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="address">Adres</label></th>
                                <td><input type="text" id="address" name="address" value="<?php echo esc_attr($registration->address); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="city">Miasto</label></th>
                                <td><input type="text" id="city" name="city" value="<?php echo esc_attr($registration->city); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="postal_code">Kod pocztowy</label></th>
                                <td><input type="text" id="postal_code" name="postal_code" value="<?php echo esc_attr($registration->postal_code); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="website">Strona</label></th>
                                <td><input type="text" id="website" name="website" value="<?php echo esc_attr($registration->website); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="message">Dodatkowe informacje</label></th>
                                <td><textarea id="message" name="message" rows="5" class="large-text"><?php echo esc_textarea($registration->message); ?></textarea></td>
                            </tr>
                        </table>
                        <?php submit_button('Zapisz zmiany'); ?>
                    </form>
                    <p><a href="?page=b2b-registrations">&laquo; Powrót do listy</a></p>
                </div>
                <?php
                return;
            }
        }
    }
    
    // Pobranie wszystkich rejestracji
    $registrations = $wpdb->get_results("SELECT * FROM $table_name ORDER BY registration_date DESC");
    ?>
    
    <div class="wrap">
        <h1>Rejestracje B2B dla Argila Amazonia</h1>

        <?php $notification_email = get_option('mi_b2b_notification_email', get_option('admin_email')); ?>
        <form method="post" action="options.php" style="margin-bottom:20px;">
            <?php settings_fields('mi-b2b-settings-group'); ?>
            <label for="mi_b2b_notification_email"><strong>Email do powiadomień:</strong></label>
            <input type="email" id="mi_b2b_notification_email" name="mi_b2b_notification_email" value="<?php echo esc_attr($notification_email); ?>" class="regular-text" />
            <?php submit_button('Zapisz', 'primary', 'submit', false); ?>
        </form>

        <?php
        $raw_stats = $wpdb->get_results("SELECT business_category, status, COUNT(*) as cnt FROM $table_name GROUP BY business_category, status", ARRAY_A);
        if ($raw_stats) :
            $stats = [];
            foreach ($raw_stats as $row) {
                $cat = $row['business_category'];
                if (!isset($stats[$cat])) {
                    $stats[$cat] = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
                }
                $stats[$cat][$row['status']] = intval($row['cnt']);
            }
        ?>
        <h2>Statystyki klientów B2B</h2>
        <table class="widefat" style="max-width:800px;margin-bottom:20px;">
            <thead>
                <tr>
                    <th>Kategoria</th>
                    <th>Razem</th>
                    <th>Oczekuje</th>
                    <th>Zaakceptowane</th>
                    <th>Odrzucone</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $categories_map = [
                    'salon_fryzjerski' => 'Salon fryzjerski',
                    'barber_shop' => 'Barber shop',
                    'gabinet_trychologiczny' => 'Gabinet trychologiczny',
                    'gabinet_kosmetyczny' => 'Gabinet kosmetyczny',
                    'szkola_akademia_fryzjerska' => 'Szkoła / akademia fryzjerska',
                    'pracownia_makijazu_wizazu' => 'Pracownia makijażu / wizażu',
                    'hurtownia_fryzjerska_kosmetyczna' => 'Hurtownia fryzjerska / kosmetyczna',
                    'drogeria' => 'Drogeria',
                    'sklep_stacjonarny' => 'Sklep stacjonarny',
                    'sklep_internetowy' => 'Sklep internetowy',
                    'influencer_tworca_internetowy' => 'Influencer / twórca internetowy',
                    'organizator_eventow' => 'Organizator eventów',
                    'klub_fitness_joga' => 'Klub fitness, joga',
                    'silownia' => 'Siłownia',
                    'inny_obiekt_sportowy' => 'Inny obiekt sportowy',
                    'hotel' => 'Hotel',
                    'spa_salon_masazu' => 'Spa / Salon masażu',
                    'inne' => 'Inne...'
                ];
                foreach ($stats as $cat => $counts) :
                    $name = isset($categories_map[$cat]) ? $categories_map[$cat] : $cat;
                    $total = $counts['approved'] + $counts['pending'] + $counts['rejected'];
                ?>
                <tr>
                    <td><?php echo esc_html($name); ?></td>
                    <td><?php echo intval($total); ?></td>
                    <td><?php echo intval($counts['pending']); ?></td>
                    <td><?php echo intval($counts['approved']); ?></td>
                    <td><?php echo intval($counts['rejected']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <style>
            .b2b-table {
                background: #fff;
                border: 1px solid #ccc;
                border-radius: 6px;
                overflow: hidden;
                margin-top: 20px;
                box-shadow: 0 2px 6px rgba(0,0,0,0.05);
                font-family: inherit;
            }
            .b2b-table table {
                width: 100%;
                border-collapse: collapse;
            }
            .b2b-table th {
                background: #f0f0f0;
                padding: 12px;
                text-align: left;
                font-weight: bold;
                border-bottom: 1px solid #ddd;
                font-family: inherit;
            }
            .b2b-table td {
                padding: 12px;
                border-bottom: 1px solid #eee;
                font-family: inherit;
            }
            .b2b-table tr:hover {
                background: #f9f9f9;
            }
            .status-pending {
                background: #fff3cd;
                color: #856404;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 12px;
            }
            .status-approved {
                background: #d4edda;
                color: #155724;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 12px;
            }
            .status-rejected {
                background: #f8d7da;
                color: #721c24;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 12px;
            }
            .action-buttons a {
                margin-right: 10px;
                text-decoration: none;
            }
            .approve-btn {
                color: #155724;
                font-weight: bold;
            }
            .reject-btn {
                color: #721c24;
                font-weight: bold;
            }
            .delete-btn {
                color: #a00;
                font-weight: bold;
            }
            .edit-btn {
                color: #2271b1;
                font-weight: bold;
            }
            .details-toggle {
                cursor: pointer;
                color: #2271b1;
                text-decoration: underline;
            }
            .details-row {
                display: none;
                background: #f5f5f5;
            }
            .details-content {
                padding: 15px;
                font-family: inherit;
            }
            .details-content p {
                margin: 5px 0;
            }
        </style>
        
        <div class="b2b-table">
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Imię i nazwisko</th>
                        <th>Firma</th>
                        <th>NIP</th>
                        <th>Email</th>
                        <th>Kategoria</th>
                        <th>Status</th>
                        <th>Rola</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $reg): ?>
                    <tr>
                        <td><?php echo date('d.m.Y H:i', strtotime($reg->registration_date)); ?></td>
                        <td><?php echo esc_html($reg->full_name); ?></td>
                        <td><strong><?php echo esc_html($reg->company_name); ?></strong></td>
                        <td><?php echo esc_html($reg->nip); ?></td>
                        <td><?php echo esc_html($reg->email); ?></td>
                        <td><?php
                            $categories_map = [
                                'salon_fryzjerski' => 'Salon fryzjerski',
                                'barber_shop' => 'Barber shop',
                                'gabinet_trychologiczny' => 'Gabinet trychologiczny',
                                'gabinet_kosmetyczny' => 'Gabinet kosmetyczny',
                                'szkola_akademia_fryzjerska' => 'Szkoła / akademia fryzjerska',
                                'pracownia_makijazu_wizazu' => 'Pracownia makijażu / wizażu',
                                'hurtownia_fryzjerska_kosmetyczna' => 'Hurtownia fryzjerska / kosmetyczna',
                                'drogeria' => 'Drogeria',
                                'sklep_stacjonarny' => 'Sklep stacjonarny',
                                'sklep_internetowy' => 'Sklep internetowy',
                                'influencer_tworca_internetowy' => 'Influencer / twórca internetowy',
                                'organizator_eventow' => 'Organizator eventów',
                                'klub_fitness_joga' => 'Klub fitness, joga',
                                'silownia' => 'Siłownia',
                                'inny_obiekt_sportowy' => 'Inny obiekt sportowy',
                                'hotel' => 'Hotel',
                                'spa_salon_masazu' => 'Spa / Salon masażu',
                                'inne' => 'Inne...'
                            ];
                            echo isset($categories_map[$reg->business_category]) ? 
                                esc_html($categories_map[$reg->business_category]) : 
                                esc_html($reg->business_category);
                        ?></td>
                        <td>
                            <?php
                            $status_class = 'status-' . $reg->status;
                            $status_text = [
                                'pending' => 'Oczekuje',
                                'approved' => 'Zaakceptowane',
                                'rejected' => 'Odrzucone'
                            ][$reg->status];
                            ?>
                            <span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                        </td>
                        <td>
                            <?php
                            $user = get_user_by('email', $reg->email);
                            if ($user) :
                                $roles = get_editable_roles();
                            ?>
                            <form method="get" style="display:inline">
                                <input type="hidden" name="page" value="b2b-registrations" />
                                <input type="hidden" name="action" value="setrole" />
                                <input type="hidden" name="id" value="<?php echo $reg->id; ?>" />
                                <?php wp_nonce_field('b2b_action_nonce'); ?>
                                <select name="role">
                                    <?php foreach ($roles as $role_key => $role_data) : ?>
                                        <option value="<?php echo esc_attr($role_key); ?>" <?php selected(in_array($role_key, $user->roles)); ?>><?php echo esc_html($role_data['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="button">Zmień</button>
                            </form>
                            <?php else : ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td class="action-buttons">
                            <?php $nonce = wp_create_nonce('b2b_action_nonce'); ?>
                            <?php if ($reg->status == 'pending' || $reg->status == 'rejected'): ?>
                                <a href="?page=b2b-registrations&action=approve&id=<?php echo $reg->id; ?>&_wpnonce=<?php echo $nonce; ?>"
                                   class="approve-btn"
                                   onclick="return confirm('Czy na pewno chcesz zaakceptować to zgłoszenie?')">✓ Akceptuj</a>
                            <?php endif; ?>
                            <?php if ($reg->status == 'pending' || $reg->status == 'approved'): ?>
                                <a href="?page=b2b-registrations&action=reject&id=<?php echo $reg->id; ?>&_wpnonce=<?php echo $nonce; ?>"
                                   class="reject-btn"
                                   onclick="return confirm('Czy na pewno chcesz odrzucić to zgłoszenie?')">✗ Odrzuć</a>
                            <?php endif; ?>
                            <a href="?page=b2b-registrations&action=edit&id=<?php echo $reg->id; ?>&_wpnonce=<?php echo $nonce; ?>"
                               class="edit-btn">✎ Edytuj</a>
                            <a href="?page=b2b-registrations&action=delete&id=<?php echo $reg->id; ?>&_wpnonce=<?php echo $nonce; ?>"
                               class="delete-btn"
                               onclick="return confirm('Czy na pewno chcesz usunąć to zgłoszenie?')">🗑 Usuń</a>
                            <span class="details-toggle" data-id="<?php echo $reg->id; ?>">Szczegóły ▼</span>
                        </td>
                    </tr>
                    <tr class="details-row" id="details-<?php echo $reg->id; ?>">
                        <td colspan="9">
                            <div class="details-content">
                                <p><strong>Osoba kontaktowa:</strong> <?php echo esc_html($reg->contact_person); ?></p>
                                <p><strong>Telefon:</strong> <?php echo esc_html($reg->phone); ?></p>
                                <p><strong>Regulamin:</strong> <?php echo $reg->terms_accepted ? 'Tak' : 'Nie'; ?></p>
                                <p><strong>Adres:</strong> <?php echo esc_html($reg->address); ?>, <?php echo esc_html($reg->postal_code); ?> <?php echo esc_html($reg->city); ?></p>
                                <p><strong>Kategoria działalności:</strong> <?php
                                    $categories_map = [
                                        'salon_fryzjerski' => 'Salon fryzjerski',
                                        'barber_shop' => 'Barber shop',
                                        'gabinet_trychologiczny' => 'Gabinet trychologiczny',
                                        'gabinet_kosmetyczny' => 'Gabinet kosmetyczny',
                                        'szkola_akademia_fryzjerska' => 'Szkoła / akademia fryzjerska',
                                        'pracownia_makijazu_wizazu' => 'Pracownia makijażu / wizażu',
                                        'hurtownia_fryzjerska_kosmetyczna' => 'Hurtownia fryzjerska / kosmetyczna',
                                        'drogeria' => 'Drogeria',
                                        'sklep_stacjonarny' => 'Sklep stacjonarny',
                                        'sklep_internetowy' => 'Sklep internetowy',
                                        'influencer_tworca_internetowy' => 'Influencer / twórca internetowy',
                                        'organizator_eventow' => 'Organizator eventów',
                                        'klub_fitness_joga' => 'Klub fitness, joga',
                                        'silownia' => 'Siłownia',
                                        'inny_obiekt_sportowy' => 'Inny obiekt sportowy',
                                        'hotel' => 'Hotel',
                                        'spa_salon_masazu' => 'Spa / Salon masażu',
                                        'inne' => 'Inne...'
                                    ];
                                    echo isset($categories_map[$reg->business_category]) ? 
                                        esc_html($categories_map[$reg->business_category]) : 
                                        esc_html($reg->business_category);
                                ?></p>
                                <?php if ($reg->website): ?>
                                <p><strong>Strona:</strong> <a href="<?php echo esc_url($reg->website); ?>" target="_blank"><?php echo esc_html($reg->website); ?></a></p>
                                <?php endif; ?>
                                <?php if ($reg->message): ?>
                                <p><strong>Dodatkowe informacje:</strong><br><?php echo nl2br(esc_html($reg->message)); ?></p>
                                <?php endif; ?>
                                <?php if ($reg->approval_date): ?>
                                <p><strong>Data akceptacji:</strong> <?php echo date('d.m.Y H:i', strtotime($reg->approval_date)); ?></p>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.details-toggle').on('click', function() {
                var id = $(this).data('id');
                $('#details-' + id).toggle();
                $(this).text($(this).text() == 'Szczegóły ▼' ? 'Szczegóły ▲' : 'Szczegóły ▼');
            });
        });
        </script>
    </div>
    <?php
}

// Tworzenie konta użytkownika po akceptacji zgłoszenia
function mi_b2b_create_wp_user($registration) {
    if (email_exists($registration->email)) {
        return '';
    }

    $password = wp_generate_password(12, true);
    $user_id  = wp_create_user($registration->email, $password, $registration->email);

    if (!is_wp_error($user_id)) {
        $user = new WP_User($user_id);
        $user->set_role('b2b');

        // Uzupełnij imię i nazwisko na podstawie pełnego imienia z rejestracji
        $names = explode(' ', $registration->full_name, 2);
        $first_name = $names[0];
        $last_name  = isset($names[1]) ? $names[1] : '';

        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => $registration->full_name,
        ]);

        return $password;
    }

    return '';
}

// Funkcja wysyłania emaila z akceptacją
function mi_b2b_send_approval_email($registration, $password = '') {
    $subject = 'Twoje konto B2B zostało aktywowane - Argila Amazonia';
    $message = "Szanowni Państwo,\n\n";
    $message .= "Z przyjemnością informujemy, że Państwa zgłoszenie do programu B2B zostało zaakceptowane!\n\n";
    $message .= "Firma: " . $registration->company_name . "\n";
    $message .= "NIP: " . $registration->nip . "\n\n";
    if ($password) {
        $message .= "Twoje konto w naszym sklepie zostało utworzone. Możesz zalogować się używając adresu email: " . $registration->email . " oraz poniższego hasła:\n";
        $message .= $password . "\n\n";
    }
    $message .= "Od teraz mogą Państwo korzystać ze specjalnych warunków współpracy dla klientów B2B.\n\n";
    $message .= "Dziękujemy za zaufanie i zapraszamy do współpracy!\n\n";
    $message .= "Z poważaniem,\nZespół Argila Amazonia";

    $headers = [ 'From: Argila <biuro@argilaamazonia.pl>' ];
    wp_mail($registration->email, $subject, $message, $headers);
}

// Dodanie użytkownika do panelu, gdy jego rola zostanie zmieniona na B2B
add_action('set_user_role', 'mi_b2b_add_user_to_panel_on_role_change', 10, 3);
function mi_b2b_add_user_to_panel_on_role_change($user_id, $role, $old_roles) {
    if ($role !== 'b2b') {
        return;
    }

    $user = get_userdata($user_id);
    if (!$user) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'b2b_registrations';

    // Sprawdź, czy użytkownik już istnieje w panelu
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE email = %s", $user->user_email));
    if ($exists) {
        return;
    }

    $full_name = trim($user->first_name . ' ' . $user->last_name);
    if ($full_name === '') {
        $full_name = $user->display_name;
    }

    $wpdb->insert($table_name, [
        'full_name'        => $full_name,
        'company_name'     => '',
        'nip'              => '',
        'contact_person'   => '',
        'email'            => $user->user_email,
        'phone'            => '',
        'business_category'=> '',
        'address'          => '',
        'city'             => '',
        'postal_code'      => '',
        'website'          => '',
        'message'          => '',
        'terms_accepted'   => 0,
        'status'           => 'approved',
        'registration_date'=> current_time('mysql'),
        'approval_date'    => current_time('mysql'),
    ]);
}