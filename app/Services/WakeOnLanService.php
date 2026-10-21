<?php

namespace App\Services;

/**
 * Wake on LAN: the magic packet that tells a sleeping NIC to power the machine
 * back on.
 *
 * Why this exists on the server rather than in the agent: a machine that is
 * asleep has no agent running, so the agent is the one thing that cannot wake
 * it. The packet is a broadcast on the local segment, and the server sits on the
 * same LAN as the fleet, so it is the only party awake when a laptop is not.
 *
 * The packet itself is fixed by the protocol and has no negotiation: six 0xFF
 * bytes, then the target MAC repeated sixteen times, 102 bytes in total. The
 * repetition is what makes the packet distinctive enough for a NIC that is
 * powered but asleep to recognise it without waking the CPU.
 */
class WakeOnLanService
{
    /**
     * Port the magic packet is sent to.
     *
     * 9 is the "discard" port and the de-facto standard for WOL: the packet is
     * a wake-up trigger rather than data, so nothing listens for it. 7 is the
     * other one in common use. The NIC only needs to see the bytes on the wire,
     * so the port number is effectively ignored by the hardware - but it still
     * has to be a port the network does not filter.
     */
    private const DEFAULT_PORT = 9;

    /**
     * Send a magic packet to one MAC address.
     *
     * @param  string  $mac  The target's MAC, in any readable format.
     * @param  string|null  $broadcast  The broadcast address to send to.
     *                                  Defaults to the global broadcast, which is
     *                                  what a machine on another subnet needs;
     *                                  a subnet broadcast does not cross a
     *                                  router, and the global one is stopped by
     *                                  most enterprise networks, so it can be
     *                                  overridden per deployment.
     */
    public function send(string $mac, ?string $broadcast = null, int $port = self::DEFAULT_PORT): bool
    {
        $packet = self::magicPacket($mac);

        if ($packet === null) {
            return false;
        }

        $target = $broadcast ?: '255.255.255.255';

        $socket = @stream_socket_client(
            'udp://'.$target.':'.$port,
            $errno,
            $errstr,
            1.0,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            return false;
        }

        try {
            // SO_BROADCAST is deliberately absent here, and the subnet
            // broadcast is preferred for exactly that reason.
            //
            // PHP offers no way to set SO_BROADCAST on a stream: the function
            // this used to call, stream_set_option(), does not exist, and the
            // one that does - stream_socket_set_option() - takes no SOL_SOCKET
            // options. Calling it threw an Error that the catch below turned
            // into "false", so send() returned false every time and no packet
            // was ever written: the Wake button was a silent no-op.
            //
            // Without SO_BROADCAST the kernel refuses a global broadcast at
            // connect time with EACCES (errno 13), but it accepts a directed
            // broadcast to a device's own /24 - which is why the controller
            // derives one from the device IP. Falling back to 255.255.255.255
            // therefore still fails, and says so, rather than pretending.
            $written = @fwrite($socket, $packet);

            return $written === strlen($packet);
        } catch (\Throwable) {
            return false;
        } finally {
            @fclose($socket);
        }
    }

    /**
     * Build the 102-byte magic packet for a MAC address.
     *
     * Public and static so the shape of the packet can be asserted in a test
     * without opening a socket: the protocol is fixed, so the bytes are the
     * contract.
     */
    public static function magicPacket(string $mac): ?string
    {
        $hex = self::normaliseMac($mac);

        if ($hex === null) {
            return null;
        }

        $packet = str_repeat("\xFF", 6);

        for ($i = 0; $i < 16; $i++) {
            $packet .= hex2bin(substr($hex, 0, 2));
            $packet .= hex2bin(substr($hex, 2, 2));
            $packet .= hex2bin(substr($hex, 4, 2));
            $packet .= hex2bin(substr($hex, 6, 2));
            $packet .= hex2bin(substr($hex, 8, 2));
            $packet .= hex2bin(substr($hex, 10, 2));
        }

        return $packet;
    }

    /**
     * Reduce a MAC address to bare uppercase hex, or null when it is not one.
     *
     * Windows reports colons (34:5A:...), the asset register stores dashes
     * (34-5A-...), and an operator may type either. A MAC is exactly six
     * octets; anything else is a typo or a placeholder like "N/A" and must not
     * be turned into a packet aimed at a random address.
     */
    public static function normaliseMac(?string $mac): ?string
    {
        if ($mac === null) {
            return null;
        }

        $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $mac));

        return strlen($hex) === 12 ? $hex : null;
    }

    /**
     * The broadcast address to aim a packet at, derived from the device's own
     * IP when one is known.
     *
     * A subnet broadcast reaches a machine on the same segment without needing
     * the global broadcast to be allowed through the network, which most
     * enterprise switches filter. It is only useful when the device's IP is
     * also known: the subnet cannot be derived from a MAC.
     */
    public static function subnetBroadcast(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $parts = explode('.', $ip);

        // The /24 is the practical assumption for a small office LAN, and the
        // fallback (global broadcast) is harmless where it is wrong: a packet
        // to the wrong broadcast address simply does not wake anything.
        $parts[3] = '255';

        return implode('.', $parts);
    }
}
