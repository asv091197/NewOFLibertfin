<?php
namespace LibertyFin\Dominio;

/**
 * Los bancos que se sugieren al anotar un pago con tarjeta o SPEI.
 *
 * Son SUGERENCIAS, no una lista cerrada: el campo es texto libre. La
 * lista sirve para que el mismo banco se escriba igual siempre ("BBVA" y
 * no "bbva", "Bancomer" o "BBVA Bancomer"), y así se pueda cuadrar contra
 * el estado de cuenta. Si el banco no está, se escribe y ya.
 */
final class Bancos
{
    const SUGERIDOS = [
        'BBVA', 'Banxico', 'Santander', 'Banorte', 'Citibanamex', 'HSBC', 'Scotiabank',
        'Inbursa', 'Banco Azteca', 'BanCoppel', 'Banregio', 'Afirme', 'BanBajío',
        'Banjército', 'Bansí', 'Multiva', 'Hey Banco', 'Nu', 'Mercado Pago',
        'Spin by OXXO', 'Klar', 'Stori', 'Ualá', 'Openbank', 'Albo', 'Fondeadora',
        'American Express', 'Otro',
    ];

    /** El <datalist> con las sugerencias, para un <input list="…">. */
    public static function datalist($id)
    {
        $h = '<datalist id="' . htmlspecialchars($id, ENT_QUOTES) . '">';
        foreach (self::SUGERIDOS as $b) {
            $h .= '<option value="' . htmlspecialchars($b, ENT_QUOTES) . '">';
        }
        return $h . '</datalist>';
    }
}
