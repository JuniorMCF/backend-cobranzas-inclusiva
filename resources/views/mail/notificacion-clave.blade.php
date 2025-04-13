<!DOCTYPE html>
<html>
<head>
    <title>Cambio de contraseña | {{ config('app.name') }}</title>
    <style>
        /* Estilos generales */
        body {
            font-family: Arial, sans-serif;
            background-color: #f2f2f2;
            margin: 0;
            padding: 0;
            font-size: 14px;
        }

        .email-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #ffffff;
            border: 1px solid #e5e5e5;
            border-radius: 5px;
        }

        /* Estilos para el logo SVG */
        .logo-svg {
            text-align: center;
            margin: 20px 0px;
        }

        .img-logo {
            max-width: 100%;
            height: 50px;
        }

        /* Estilos para el título */
        h1 {
            text-align: left;
            color: #005295;
            margin: 20px 0px;
            font-size: 1.4rem;
        }

        /* Estilos para el párrafo principal */
        .main-paragraph {
            line-height: 1.6;
            margin-bottom: 20px;
        }

        /* Estilos para los datos del usuario */
        .user-info {
            padding: 10px;
            background-color: #f5f5f5;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .secret-data {
            font-size: 14px;
            font-weight: bold;
        }

        /* Estilos para los botones */
        .button {
            display: inline-block;
            padding: 12px 20px;
            background-color: #005d9b;
            color: #ffffff;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: bold;
        }

        .button:hover {
            background-color: #005d9b;
        }

        /* Estilos para el mensaje de soporte */
        .support-message {
            padding: 10px;
            background-color: #f5f5f5;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .whatsapp-link {
            color: #25d366 !important;
            text-decoration: none;
            font-weight: bold;
        }

        /* Estilos para el pie de página */
        .footer {
            margin-top: 20px;
            text-align: center;
            color: #777;
            font-size: 12px;
        }

        .asterisks {
            font-size: 14px;
        }

        a {
            text-decoration: none !important;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Logo SVG -->
        <!--<div class="logo-svg">
             <img class="img-logo" src="{{ asset('images/logo.jpg') }}" alt="Logo">
        </div>-->

        <h1>Cambio de clave digital</h1>

        <div class="main-paragraph">
            <p><strong>Estimado/a {{ $user->nom }},</strong></p>
            <p>Hemos recibido una solicitud para cambiar la contraseña de tu cuenta. Si no has realizado este cambio, te recomendamos que tomes medidas de seguridad de inmediato.</p>
        </div>

        <div class="user-info">
            <p><span class="secret-data">Número de documento (DNI/RUC) :</span>
                {{ substr($user->dni, 0, 1) . '********' . substr($user->dni, -2) }}</p>
            <p><span class="secret-data">Pregunta secreta:</span> {{ $user->secret_question }}</p>
            <p><span class="secret-data">Respuesta secreta:</span>
                {{ substr($user->secret_answer, 0, 1) . '*******'  }}</p>
            <p><span class="asterisks">* Por motivos de seguridad, algunos datos se muestran parcialmente.</span></p>
        </div>

        <div class="support-message">
            <p>Si no has realizado este cambio o necesitas asistencia adicional, por favor contacta a nuestro equipo de soporte visitando nuestra página web:</p>
        </div>

        <div class="footer">
            <p>No respondas a este correo. Si tienes alguna duda, contáctanos a través de nuestro equipo de soporte.</p>
            <p>© {{ date('Y') }} {{ config('app.name') }}. Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>
