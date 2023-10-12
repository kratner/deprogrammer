interface ACastAudioProps {
    contentID: string,
}

const ACastAudio:  React.FC<ACastAudioProps> = ( {contentID} ) => {
    return (
        <iframe src={`${contentID}`} frameBorder="0" width="100%" height="110px" allow="autoplay"></iframe>
    )
}

export default ACastAudio;
