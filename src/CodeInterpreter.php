<?php
class CodeInterpreter {
    public string $chat_dir;
    public string $data_dir;
    protected array $settings;

    public function __construct(
        protected int $chat_id
    ) {
        $this->settings = require( __DIR__ . "/../settings.php" );

        $this->chat_dir = "data/" . $chat_id;
        $this->data_dir = $this->chat_dir . "/data";

        $chat_dir = $this->chat_dir;
        $data_dir = $this->data_dir;

        mkdir( __DIR__ . "/../" . $data_dir, 0777, true );

        register_shutdown_function( function() use ( $data_dir, $chat_dir ) {
            if( count( glob( $data_dir . "/*" ) ) === 0 ) {
                if( is_dir( $data_dir ) ) {
                    rmdir( $data_dir );
                    rmdir( $chat_dir );
                }
            }
        } );
    }

    public function init_chatgpt( ChatGPT $chatgpt ) {
        $chatgpt->smessage( "You are an AI assistant that can read files and run Python code in order to answer the user's question. You can access a folder called 'data/' from the Python code to read or write files. Always save visualizations and charts into a file. When creating links to files in the data directory in your response, use the format [link text](data/filename). When the task requires to process or read user provided data from files, always read the file content first, before running Python code. Don't assume the contents of files. When processing CSV files, read the file first before writing any Python code. You can also use Python code to download files or images from URLs. Note that Python code will always be run in an isolated environment, without access to variables from previous code. You can include images in your response with the format '![image name](data/image_filename.jpg)'. Include visualizations as images in your response." );
        $chatgpt->add_function( [$this, "read_file_contents"] );
        $chatgpt->add_function( [$this, "python"] );

        return $chatgpt;
    }

    /**
     * Determines the Python command to run based on the
     * operating system or the settings
     *
     * @return string The python command
     */
    public function get_python_command(): string {
        if( isset( $this->settings['python_command'] ) ) {
            return $this->settings['python_command'];
        }

        if( stripos( PHP_OS, "win" ) === 0 ) {
            return "python";
        }

        return "python3";
    }

    public function run_python_code( string $code ): PythonResult {
        $output = [];
        $result_code = NULL;

        $data_dir_full_path = __DIR__ . "/../" . $this->data_dir;
        $sandbox_settings = $this->settings['code_interpreter']['sandbox'];

        // TODO: create a way to run isolated Python code even
        //       when the app is running inside a Docker container
        if( ($sandbox_settings['enabled'] ?? false) === true ) {
            if( ! isset( $sandbox_settings['container'] ) ) {
                throw new \Exception( "Container name missing from settings" );
            }

            $container_name = $sandbox_settings["container"];
            $python_i_command = "echo ".escapeshellarg( $code )." | python3 -i";

            exec( "docker run -i --rm -v " . escapeshellarg( $data_dir_full_path ) . ":/usr/src/app/data " . escapeshellarg( $container_name ) . " bash -c " . escapeshellarg( $python_i_command ) . " 2>&1", $output, $result_code );
        } else {
            $python_i_command = "echo ".escapeshellarg( $code )." | " . $this->get_python_command() . " -i";
            exec( "cd " . escapeshellarg( $this->chat_dir ) . "; " . $python_i_command . " 2>&1", $output, $result_code );
        }

        return new PythonResult(
            output: implode( "\n", $output ),
            result_code: $result_code,
        );
    }

    public function get_filename( string $filename ): string {
        if( strpos( $filename, "data/" ) !== 0 ) {
            $filename = "data/" . $filename;
        }

        return $this->chat_dir . "/" . $filename;
    }

    /**
     * Read the contents of a file
     *
     * @param string $filename The name of the file to read
     * @param int $line_count How many lines to read (-1 = all lines)
     */
    public function read_file_contents( string $filename, ?int $line_count = null ) {
        $filename = $this->get_filename( $filename );

        if( $line_count === -1 ) {
            $line_count = null;
        }

        $how_many = $line_count === null ? "ALL": $line_count;

        error_log( "INFO: Reading " . $how_many . " lines from file: " . $filename );

        if( ! file_exists( $filename ) ) {
            return "<file not found>";
        }

        if( ! is_readable( $filename ) ) {
            return "<file is not readable>";
        }

        // TODO: read lines more efficiently
        $lines = file( $filename );

        if( $lines === false ) {
            return "<unable to read file>";
        }

        $lines = array_slice( $lines, 0, $line_count );

        $contents = implode( "\n", $lines );

        if( trim( $contents ) == "" ) {
            return "<file is empty>";
        }

        return $contents;
    }

    /**
     * Run python code
     *
     * @param string $code The code to run. Code must have a print statement in the end that prints out the relevant return value
     */
    public function python( string $code ): string {
        $code = trim( $code );

        // fix ChatGPT hallucinations
        if( str_contains( $code, '"code": "' ) ) {
            error_log( "NOTICE: Fixing ChatGPT hallucinated arguments" );

            $code = explode( '"code": "', $code, 2 );
            $code = trim( $code[1] );
            $code = trim( rtrim( $code, '}' ) );
            $code = trim( rtrim( $code, '"' ) );

            // convert "\n" to newline
            $code = str_replace( '\n', "\n", $code );
        }

        $result = $this->run_python_code( $code );

        return json_encode( [
            "output" => $result->output,
            "result_code" => $result->result_code,
        ] );
    }

    // alias for ChatGPT hallucinations
    public function pythoncode( string $code ): string {
        error_log( "NOTICE: ChatGPT ran hallucinated 'pythoncode' function" );
        return $this->python( $code );
    }

    /**
     * Imitate streaming since we can't stream function calls
     *
     * @param string $text The text to stream
     */
    public function fake_stream( string $text ): void {
        $chunks = str_split( $text, 42 );

        foreach( $chunks as $chunk ) {
            echo "data: " . json_encode( ["content" => $chunk ] ) ."\n\n";
            flush();
            usleep( 1000 * 50 );
        }
    }
}
